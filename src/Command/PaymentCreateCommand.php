<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Edi\Edi835ChargePayment;
use PossiblePromise\QbHealthcare\Edi\Edi835ClaimPayment;
use PossiblePromise\QbHealthcare\Edi\Edi835Reader;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Exception\PaymentCreationException;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\ClaimsRepository;
use PossiblePromise\QbHealthcare\Repository\CreditMemosRepository;
use PossiblePromise\QbHealthcare\Repository\InvoicesRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Repository\PaymentsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payment:create',
    description: 'Create a payment.'
)]
final class PaymentCreateCommand extends Command
{
    use PaymentCreateTrait;

    /**
     * Caches the charges to be updated so they can be restored if needed.
     *
     * @var Charge[]
     */
    private array $cachedCharges = [];

    public function __construct(
        private ChargesRepository $charges,
        private ClaimsRepository $claims,
        private PayersRepository $payers,
        private InvoicesRepository $invoices,
        private CreditMemosRepository $creditMemos,
        private ItemsRepository $items,
        private PaymentsRepository $payments,
        private QuickBooks $qb
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a payment, by processing an ERA 835 document.')
            ->addArgument('eraFile', InputArgument::REQUIRED, 'ERA 835 file to read')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Payment');

        $file = $input->getArgument('eraFile');

        $era835 = new Edi835Reader($file);
        $payment = $era835->process();

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $message = sprintf(
            'Did you receive a payment from %s for %s on %s?',
            $payment->payerName,
            $fmt->formatCurrency((float) $payment->payment, 'USD'),
            $payment->paymentDate->format('Y-m-d')
        );
        if (!$io->confirm($message)) {
            return Command::INVALID;
        }

        /** @var Claim[] $claims */
        $claims = [];
        $unfinishedClaimIds = [];

        try {
            foreach ($payment->claims as $claim) {
                $claim = $this->processClaim($payment->paymentRef, $payment->paymentDate, $claim);
                $unfinishedKey = array_search($claim->getBillingId(), $unfinishedClaimIds, true);

                if (bccomp($claim->getBalance(), '0.00', 2) === 0) {
                    $claims[] = $claim;

                    if ($unfinishedKey !== false) {
                        unset($unfinishedClaimIds[$unfinishedKey]);
                    }
                } elseif ($unfinishedKey === false) {
                    $unfinishedClaimIds[] = $claim->getBillingId();
                }
            }
        } catch (PaymentCreationException $e) {
            $this->restoreCharges();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!empty($unfinishedClaimIds)) {
            $io->error('The following claims have remaining balances after the payment is applied:');
            $io->listing($unfinishedClaimIds);
            $this->restoreCharges();

            return Command::FAILURE;
        }

        self::showPaidClaims($claims, $payment->providerAdjustments, $io);

        if (!$io->confirm('Continue?', false)) {
            $this->restoreCharges();

            return Command::SUCCESS;
        }

        try {
            $this->syncToQb($payment->paymentRef, $payment->paymentDate, $claims, $payment->providerAdjustments, $io);
        } catch (PaymentCreationException $e) {
            $this->restoreCharges();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Payment %s has been processed successfully.', $payment->paymentRef));

        return Command::SUCCESS;
    }

    private function processClaim(string $paymentRef, \DateTimeImmutable $paymentDate, Edi835ClaimPayment $claim): ?Claim
    {
        $charges = [];

        foreach ($claim->charges as $chargePayment) {
            $charges[] = $this->processCharge(
                $paymentRef,
                $paymentDate,
                $claim->clientLastName,
                $claim->clientFirstName,
                $chargePayment
            );
        }

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $claim = $this->claims->findOneByCharges($charges);

        if ($claim === null) {
            throw new PaymentCreationException('No claim found for the matched charges.');
        }
        if ($claim->getBillingId() === null) {
            throw new PaymentCreationException(sprintf(
                'The claim billed on %s for %s, for client %s, with charges from %s to %s, does not have a billing ID.',
                $claim->getPaymentInfo()->getBilledDate()->format('Y-m-d'),
                $fmt->formatCurrency((float) $claim->getBilledAmount(), 'USD'),
                $claim->getClientName(),
                $claim->getStartDate()->format('Y-m-d'),
                $claim->getEndDate()->format('Y-m-d')
            ));
        }

        return $claim;
    }

    private function processCharge(
        string $paymentRef,
        \DateTimeImmutable $paymentDate,
        ?string $lastName,
        ?string $firstName,
        Edi835ChargePayment $chargePayment
    ): Charge {
        $charges = $this->charges->findBySvcData(
            billingCode: $chargePayment->billingCode,
            billedAmount: $chargePayment->billed,
            units: $chargePayment->units,
            serviceDate: $chargePayment->serviceDate,
            lastName: $lastName,
            firstName: $firstName
        );

        if ($charges->count() === 0) {
            throw new PaymentCreationException('No charges match this line item.');
        }
        if ($charges->count() > 1) {
            throw new PaymentCreationException('Multiple charges matched. Unable to continue.');
        }

        /** @var Charge $charge */
        $charge = $charges->shift();

        self::validateChargeAdjustments(
            $charge,
            $chargePayment->contractualAdjustment,
            $chargePayment->coinsurance,
            $chargePayment->billed,
            $chargePayment->paid
        );

        // Cache the existing charge in case there are any errors
        $this->cachedCharges[] = clone $charge;

        $charge
            ->setPayerBalance('0.00')
            ->getPrimaryPaymentInfo()
            ->setPaymentDate($paymentDate)
            ->setPaymentRef($paymentRef)
            ->setPayment($chargePayment->paid)
            ->setCoinsurance($chargePayment->coinsurance)
        ;

        $this->charges->save($charge);

        return $charge;
    }

    private function restoreCharges(): void
    {
        foreach ($this->cachedCharges as $charge) {
            $this->charges->save($charge);
        }
    }
}
