<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustmentType;
use PossiblePromise\QbHealthcare\Exception\PaymentCreationException;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\ClaimsRepository;
use PossiblePromise\QbHealthcare\Repository\CreditMemosRepository;
use PossiblePromise\QbHealthcare\Repository\InvoicesRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Repository\PaymentsRepository;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payment:create:manual',
    description: 'Create a payment manually.'
)]
final class PaymentCreateManualCommand extends Command
{
    use PaymentCreateTrait;

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
            ->setHelp('Allows you to create a payment manually.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Payment');

        /** @var \DateTimeImmutable $depositDate */
        $depositDate = $io->ask('Payment date', null, self::validateDate(...));
        $paymentRef = $io->ask('Check number', null, self::validateRequired(...));
        $paymentAmount = $io->ask('Payment amount', null, self::validateAmount(...));

        $paymentTotal = '0.00';
        /** @var Claim[] $claims */
        $claims = [];

        try {
            do {
                $claim = $this->processClaim($paymentRef, $depositDate, $io);

                $paymentTotal = bcadd($paymentTotal, $claim->getPaymentInfo()->getPayment(), 2);
                $claims[] = $claim;
            } while ($io->confirm('Is there another claim to add?'));
        } catch (PaymentCreationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Now capture any provider level adjustments
        $providerAdjustments = [];

        while (bccomp($paymentTotal, $paymentAmount, 2) !== 0) {
            $providerAdjustment = self::processProviderAdjustment($io);
            $paymentTotal = bcadd($paymentTotal, $providerAdjustment->getAmount(), 2);
            $providerAdjustments[] = $providerAdjustment;
        }

        self::showPaidClaims($claims, $providerAdjustments, $io);

        if (!$io->confirm('Continue?', false)) {
            return Command::SUCCESS;
        }

        try {
            $this->syncToQb($paymentRef, $depositDate, $claims, $providerAdjustments, $io);
        } catch (PaymentCreationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Payment %s has been processed successfully.', $paymentRef));

        return Command::SUCCESS;
    }

    public static function validateRequired(?string $value): string
    {
        if (empty($value)) {
            throw new \RuntimeException('A value is required.');
        }

        return $value;
    }

    private static function validateAmount(?string $value): string
    {
        if ($value === null) {
            throw new \RuntimeException('Value is required.');
        }

        if (!is_numeric($value)) {
            throw new \RuntimeException('Value must be a number.');
        }

        return $value;
    }

    private static function validateDate(string $answer): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('m/d/Y', $answer);
        if ($date === false) {
            throw new \RuntimeException('Date must be in the format: mm/dd/yyyy.');
        }

        return $date->modify('00:00:00');
    }

    private function processClaim(string $paymentRef, \DateTimeInterface $paymentDate, SymfonyStyle $io): Claim
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $billingId = $io->ask('Claim billing ID', null, self::validateRequired(...));
        $claim = $this->claims->findOneByBillingId($billingId);
        $chargeIds = new FilterableArray($claim->getCharges());
        $totalBilled = '0.00';
        $totalPaid = '0.00';

        while (bccomp($totalBilled, $claim->getBilledAmount(), 2) !== 0) {
            $charge = $this->processCharge(
                $paymentRef,
                $paymentDate,
                $claim->getClientName(),
                $io
            );

            if (!$chargeIds->contains($charge->getChargeLine())) {
                throw new PaymentCreationException(sprintf(
                    'Claim %s does not contain charge %s.',
                    $claim->getBillingId(),
                    $charge->getChargeLine()
                ));
            }

            $chargeIds->remove($charge->getChargeLine());

            $totalBilled = bcadd($totalBilled, $charge->getBilledAmount(), 2);
            $totalPaid = bcadd($totalPaid, $charge->getPrimaryPaymentInfo()->getPayment(), 2);

            $io->definitionList(
                ['Billed' => $fmt->formatCurrency((float) $totalBilled, 'USD')],
                ['Paid' => $fmt->formatCurrency((float) $totalPaid, 'USD')]
            );
        }

        // Refresh claim to get updated numbers
        return $this->claims->get($claim->getId());
    }

    private function processCharge(
        string $paymentRef,
        \DateTimeInterface $paymentDate,
        string $clientName,
        SymfonyStyle $io
    ): Charge {
        $serviceDate = $io->ask('Service date', null, self::validateDate(...));
        $billingCode = $io->ask('Billing code', null, self::validateRequired(...));
        $chargeBilled = $io->ask('Amount billed', null, self::validateAmount(...));
        $chargePaid = $io->ask('Amount paid', null, self::validateAmount(...));

        $charges = $this->charges->findByLineItem(
            serviceDate: $serviceDate,
            billingCode: $billingCode,
            billedAmount: $chargeBilled,
            clientName: $clientName
        );

        if ($charges->count() === 0) {
            throw new PaymentCreationException('No charges match this line item.');
        }
        if ($charges->count() > 1) {
            throw new PaymentCreationException('Multiple charges matched. Unable to continue.');
        }

        /** @var Charge $charge */
        $charge = $charges->shift();

        $expectedContractualAdjustment = bcsub($charge->getBilledAmount(), $charge->getContractAmount(), 2);
        $contractualAdjustment = $io->ask('Contractual adjustment', $expectedContractualAdjustment, self::validateAmount(...));
        $coinsurance = $io->ask('Coinsurance', '0.00', self::validateAmount(...));

        self::validateChargeAdjustments(
            $charge,
            $contractualAdjustment,
            $coinsurance,
            $chargeBilled,
            $chargePaid
        );

        $charge
            ->getPrimaryPaymentInfo()
            ->setPaymentDate($paymentDate)
            ->setPaymentRef($paymentRef)
            ->setPayment($chargePaid)
            ->setCoinsurance($coinsurance)
        ;

        $charge->setPayerBalance('0.00');

        $this->charges->save($charge);

        return $charge;
    }

    private static function processProviderAdjustment(SymfonyStyle $io): ProviderAdjustment
    {
        if ($io->ask('Is there a provider level adjustment?') === false) {
            throw new PaymentCreationException('Payment total is not adding up. Please try again.');
        }

        $type = $io->choice('Adjustment type', [
            self::getReadableProviderAdjustmentType(ProviderAdjustmentType::interest),
            self::getReadableProviderAdjustmentType(ProviderAdjustmentType::origination_fee),
        ]);

        $amount = $io->ask('Adjustment amount', null, self::validateAmount(...));

        return new ProviderAdjustment(
            ProviderAdjustmentType::from($type),
            $amount
        );
    }
}
