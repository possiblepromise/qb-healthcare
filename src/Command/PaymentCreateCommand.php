<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Edi\Edi835ChargePayment;
use PossiblePromise\QbHealthcare\Edi\Edi835ClaimPayment;
use PossiblePromise\QbHealthcare\Edi\Edi835Payment;
use PossiblePromise\QbHealthcare\Edi\Edi835Reader;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payer;
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
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPLine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'payment:create',
    description: 'Create a payment.'
)]
final class PaymentCreateCommand extends Command
{
    private const PROCESSED_PAYMENTS_PATH = 'var/processed/payments';

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
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Restore the charges in the payment in case something goes wrong')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Payment');

        $file = $input->getArgument('eraFile');

        $restore = $input->getOption('restore');

        $era835 = new Edi835Reader($file);
        $payments = $era835->process();

        $allPaymentsProcessed = true;
        $errors = false;

        foreach ($payments as $i => $payment) {
            $io->section(sprintf('Processing Payment %d of %d', $i + 1, \count($payments)));

            try {
                $processed = $this->processPayment($payment, $restore, $io);

                if ($processed === false) {
                    $allPaymentsProcessed = false;
                }
            } catch (\Exception) {
                $errors = true;
            }
        }

        if ($allPaymentsProcessed === true && $errors === false) {
            self::moveProcessedPayment($file);
        }

        if ($errors === true) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return bool Whether the payment was processed
     *
     * @throws \Exception If an error occurs
     */
    private function processPayment(Edi835Payment $payment, bool $restore, SymfonyStyle $io): bool
    {
        if ($this->payments->get($payment->paymentRef) !== null) {
            $io->error('Payment ' . $payment->paymentRef . ' has already been processed.');

            return true;
        }

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $message = sprintf(
            'Did you receive a payment from %s for %s on %s?',
            $payment->payerName,
            $fmt->formatCurrency((float) $payment->payment, 'USD'),
            $payment->paymentDate->format('Y-m-d')
        );
        if (!$io->confirm($message)) {
            return false;
        }

        $this->cachedCharges = [];

        /** @var Claim[] $claims */
        $claims = [];
        $unfinishedClaimIds = [];

        try {
            foreach ($payment->claims as $claim) {
                $claim = $this->processClaim($payment->paymentRef, $payment->paymentDate, $claim, $restore);
                $unfinishedKey = array_search($claim->getBillingId(), $unfinishedClaimIds, true);

                if ($restore === true) {
                    if ($unfinishedKey === false) {
                        $unfinishedClaimIds[] = $claim->getBillingId();
                    }

                    continue;
                }

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

            throw $e;
        }

        if ($restore === true) {
            $io->success(\MessageFormatter::formatMessage(
                'en_US',
                '{0, plural, one {# claim was restored} other {# claims were restored}}',
                [\count($unfinishedClaimIds)]
            ));

            return false;
        }

        if (!empty($unfinishedClaimIds)) {
            $io->error('The following claims have remaining balances after the payment is applied:');
            $io->listing($unfinishedClaimIds);
            $this->restoreCharges();

            throw new \Exception();
        }

        self::showPaidClaims($claims, $payment->providerAdjustments, $io);

        if (!$io->confirm('Continue?', false)) {
            $this->restoreCharges();

            return false;
        }

        try {
            $this->syncToQb($payment->paymentRef, $payment->paymentDate, $claims, $payment->providerAdjustments, $io);
        } catch (PaymentCreationException $e) {
            $this->restoreCharges();
            $io->error($e->getMessage());

            throw $e;
        }

        $io->success(sprintf('Payment %s has been processed successfully.', $payment->paymentRef));

        return true;
    }

    private function processClaim(string $paymentRef, \DateTimeImmutable $paymentDate, Edi835ClaimPayment $claim, bool $restore = false): ?Claim
    {
        $charges = [];

        foreach ($claim->charges as $chargePayment) {
            $charges[] = $this->processCharge(
                $paymentRef,
                $paymentDate,
                $claim->clientLastName,
                $claim->clientFirstName,
                $chargePayment,
                $restore
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
        Edi835ChargePayment $chargePayment,
        bool $restore = false
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

        self::validateChargeAdjustments($charge, $chargePayment->contractualAdjustment);

        if ($restore === true) {
            $charge
                ->setPayerBalance($charge->getBilledAmount())
                ->getPrimaryPaymentInfo()
                ->setPaymentDate(null)
                ->setPaymentRef(null)
                ->setPayment(null)
                ->setCoinsurance('0.00')
            ;

            $this->charges->save($charge);

            return $charge;
        }

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

    private static function validateChargeAdjustments(Charge $charge, string $contractualAdjustment): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $expectedContractualAdjustment = bcsub(
            $charge->getBilledAmount(),
            $charge->getContractAmount(),
            2
        );

        if (bccomp($contractualAdjustment, $expectedContractualAdjustment, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'This line includes a contractual adjustment of %s, but %s was expected.',
                $fmt->formatCurrency((float) $contractualAdjustment, 'USD'),
                $fmt->formatCurrency((float) $expectedContractualAdjustment, 'USD')
            ));
        }
    }

    /**
     * @param Claim[]              $claims
     * @param ProviderAdjustment[] $providerAdjustments
     */
    private static function showPaidClaims(
        array $claims,
        array $providerAdjustments,
        SymfonyStyle $io
    ): void {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $table = [];
        $paymentTotal = '0.00';

        foreach ($claims as $claim) {
            $table[] = [
                $claim->getBillingId(),
                $claim->getPaymentInfo()->getBilledDate()->format('Y-m-d'),
                $claim->getStartDate()->format('n/j') . '-' . $claim->getEndDate()->format('n/j'),
                $claim->getClientName(),
                $fmt->formatCurrency((float) $claim->getPaymentInfo()->getPayment(), 'USD'),
            ];

            $paymentTotal = bcadd($paymentTotal, $claim->getPaymentInfo()->getPayment(), 2);
        }

        foreach ($providerAdjustments as $providerAdjustment) {
            $table[] = [
                self::getReadableProviderAdjustmentType($providerAdjustment->getType()),
                '',
                '',
                '',
                $fmt->formatCurrency((float) $providerAdjustment->getAmount(), 'USD'),
            ];

            $paymentTotal = bcadd($paymentTotal, $providerAdjustment->getAmount(), 2);
        }

        $table[] = [
            'Total',
            '',
            '',
            '',
            $fmt->formatCurrency((float) $paymentTotal, 'USD'),
        ];

        $io->table(
            ['Billing ID', 'Billed Date', 'Dates', 'Client', 'Paid'],
            $table
        );
    }

    private static function getReadableProviderAdjustmentType(ProviderAdjustmentType $type): string
    {
        return match ($type) {
            ProviderAdjustmentType::interest => 'Interest owed',
            ProviderAdjustmentType::origination_fee => 'Origination fee',
        };
    }

    private function validateCoinsuranceItemSet(SymfonyStyle $io): void
    {
        if ($this->qb->getActiveCompany()->coinsuranceItem === null) {
            $items = $this->items->findAllNotIn([
                ...$this->payers->findAllServiceItemIds(),
                $this->qb->getActiveCompany()->contractualAdjustmentItem,
            ]);
            $io->text('Please select the item for coinsurance.');
            $name = $io->choice(
                'Coinsurance item',
                $items->map(static fn (IPPItem $item) => $item->FullyQualifiedName)
            );
            $coinsuranceItem = $items->selectOne(
                static fn (IPPItem $item) => $item->FullyQualifiedName === $name
            );
            $this->qb->getActiveCompany()->coinsuranceItem = $coinsuranceItem->Id;
            $this->qb->save();
        }
    }

    private function validateInterestItemSet(SymfonyStyle $io): void
    {
        if ($this->qb->getActiveCompany()->interestItem === null) {
            $items = $this->items->findAllNotIn([
                ...$this->payers->findAllServiceItemIds(),
                $this->qb->getActiveCompany()->contractualAdjustmentItem,
                $this->qb->getActiveCompany()->coinsuranceItem,
            ]);

            $io->text('Please select the item for interest payments.');

            $name = $io->choice(
                'Interest item',
                $items->map(static fn (IPPItem $item) => $item->FullyQualifiedName)
            );

            $interestItem = $items->selectOne(
                static fn (IPPItem $item) => $item->FullyQualifiedName === $name
            );

            $this->qb->getActiveCompany()->interestItem = $interestItem->Id;
            $this->qb->save();
        }
    }

    private function validateOriginationFeeItemSet(SymfonyStyle $io): void
    {
        if ($this->qb->getActiveCompany()->originationFeeItem === null) {
            $items = $this->items->findAllNotIn([
                ...$this->payers->findAllServiceItemIds(),
                $this->qb->getActiveCompany()->contractualAdjustmentItem,
                $this->qb->getActiveCompany()->coinsuranceItem,
                $this->qb->getActiveCompany()->interestItem,
            ]);

            $io->text('Please select the item for origination fees.');

            $name = $io->choice(
                'Origination fee item',
                $items->map(static fn (IPPItem $item) => $item->FullyQualifiedName)
            );

            $originationFeeItem = $items->selectOne(
                static fn (IPPItem $item) => $item->FullyQualifiedName === $name
            );

            $this->qb->getActiveCompany()->originationFeeItem = $originationFeeItem->Id;
            $this->qb->save();
        }
    }

    private function syncAdjustmentsToQb(Claim $claim, SymfonyStyle $io): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $creditMemoIds = $claim->getQbCreditMemoIds();

        $totalAdjustments = bcsub(
            $claim->getBilledAmount(),
            $claim->getPaymentInfo()->getPayment(),
            2
        );

        $contractualAdjustments = bcsub($claim->getBilledAmount(), $claim->getContractAmount(), 2);
        $coinsurance = $claim->getPaymentInfo()->getCoinsurance();

        $creditedAdjustments = '0.00';
        $creditedContractualAdjustments = '0.00';
        $creditedCoinsurance = '0.00';

        foreach ($creditMemoIds as $creditMemoId) {
            $creditMemo = $this->creditMemos->get($creditMemoId);

            /** @var IPPLine $line */
            foreach ($creditMemo->Line as $line) {
                if ($line->DetailType !== 'SalesItemLineDetail') {
                    continue;
                }

                $creditedAdjustments = bcadd($creditedAdjustments, (string) $line->Amount, 2);

                if ($line->SalesItemLineDetail->ItemRef === $this->qb->getActiveCompany()->contractualAdjustmentItem) {
                    $creditedContractualAdjustments = bcadd($creditedContractualAdjustments, (string) $line->Amount, 2);
                } elseif ($line->SalesItemLineDetail->ItemRef === $this->qb->getActiveCompany()->coinsuranceItem) {
                    $creditedCoinsurance = bcadd($creditedCoinsurance, (string) $line->Amount, 2);
                } else {
                    $item = $this->items->get($line->SalesItemLineDetail->ItemRef);

                    throw new PaymentCreationException(
                        "Found unexpected adjustment using the item: {$item->Name}"
                    );
                }
            }
        }

        if (bccomp($contractualAdjustments, $creditedContractualAdjustments, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'The contractual adjustment is %s, but %s of contractual adjustments were credited.',
                $fmt->formatCurrency((float) $contractualAdjustments, 'USD'),
                $fmt->formatCurrency((float) $creditedContractualAdjustments, 'USD')
            ));
        }

        if (bccomp($coinsurance, $creditedCoinsurance, 2) === -1) {
            throw new PaymentCreationException(sprintf(
                'A coinsurance of %s was expected, but %s was credited.',
                $fmt->formatCurrency((float) $coinsurance, 'USD'),
                $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
            ));
        }
        if (bccomp($coinsurance, $creditedCoinsurance, 2) === 1) {
            if (bccomp($creditedCoinsurance, '0.00', 2) !== 0) {
                throw new PaymentCreationException(sprintf(
                    'A coinsurance of %s is unexpected, but %s has already been credited. We cannot yet handle this condition.',
                    $fmt->formatCurrency((float) $coinsurance, 'USD'),
                    $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
                ));
            }

            $charges = $this->charges->findByClaim($claim);
            $creditMemo = $this->creditMemos->createCoinsuranceCredit($claim, $charges);
            $io->text(sprintf(
                'Created credit memo %s for %s',
                $creditMemo->DocNumber,
                $fmt->formatCurrency((float) $creditMemo->TotalAmt, 'USD')
            ));

            $claim->addQbCreditMemo($creditMemo);
            $this->claims->save($claim);

            $creditedCoinsurance = bcadd($creditedCoinsurance, (string) $creditMemo->TotalAmt, 2);
            if (bccomp($coinsurance, $creditedCoinsurance, 2) !== 0) {
                throw new PaymentCreationException(sprintf(
                    'Expected coinsurance of %s, but got %s.',
                    $fmt->formatCurrency((float) $coinsurance, 'USD'),
                    $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
                ));
            }

            $creditedAdjustments = bcadd($creditedAdjustments, (string) $creditMemo->TotalAmt, 2);
        } else {
            $io->text(sprintf('No credit memos to create for %s.', $claim->getBillingId()));
        }

        if (bccomp($totalAdjustments, $creditedAdjustments, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'Expected total adjustments of %s, but adjustments of %s were encountered.',
                $fmt->formatCurrency((float) $totalAdjustments, 'USD'),
                $fmt->formatCurrency((float) $creditedAdjustments, 'USD')
            ));
        }
    }

    private function verifyQbInvoice(Claim $claim): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $invoice = $this->invoices->get($claim->getQbInvoiceId());
        if (bccomp((string) $invoice->TotalAmt, $claim->getBilledAmount(), 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'QuickBooks invoice %s is for the amount of %s, but %s was expected.',
                $invoice->DocNumber,
                $fmt->formatCurrency((float) $invoice->TotalAmt, 'USD'),
                $fmt->formatCurrency((float) $claim->getBilledAmount(), 'USD')
            ));
        }
    }

    /**
     * @param ProviderAdjustment[] $providerAdjustments
     */
    private function createProviderAdjustments(
        string $paymentRef,
        \DateTimeInterface $paymentDate,
        Payer $payer,
        array $providerAdjustments,
        SymfonyStyle $io
    ): void {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        foreach ($providerAdjustments as $providerAdjustment) {
            if (bccomp($providerAdjustment->getAmount(), '0.00', 2) === 1) {
                $entity = $this->invoices->createFromProviderAdjustment($paymentRef, $paymentDate, $payer, $providerAdjustment);
            } elseif (bccomp($providerAdjustment->getAmount(), '0.00', 2) === -1) {
                $entity = $this->creditMemos->createFromProviderAdjustment($paymentRef, $paymentDate, $payer, $providerAdjustment);
            } else {
                throw new \RuntimeException('Provider adjustment is 0.');
            }

            $io->text(sprintf(
                self::getProviderAdjustmentCreationMessage($providerAdjustment),
                $entity->DocNumber,
                $fmt->formatCurrency((float) $entity->TotalAmt, 'USD')
            ));

            $providerAdjustment->setQbEntity($entity);
        }
    }

    private static function getProviderAdjustmentCreationMessage(ProviderAdjustment $providerAdjustment): string
    {
        return match ($providerAdjustment->getType()) {
            ProviderAdjustmentType::interest => 'Created invoice %s for %s of interest.',
            ProviderAdjustmentType::origination_fee => 'Created credit memo %s for a %s origination fee.',
        };
    }

    /**
     * @param Claim[]              $claims
     * @param ProviderAdjustment[] $providerAdjustments
     */
    private function syncToQb(
        string $paymentRef,
        \DateTimeInterface $depositDate,
        array $claims,
        array $providerAdjustments,
        SymfonyStyle $io
    ): void {
        $this->validateCoinsuranceItemSet($io);

        // Verify the credits in QB
        foreach ($claims as $claim) {
            $this->verifyQbInvoice($claim);
            $this->syncAdjustmentsToQb($claim, $io);
        }

        if (!empty($providerAdjustments)) {
            $this->validateInterestItemSet($io);
            $this->validateOriginationFeeItemSet($io);

            $this->createProviderAdjustments(
                $paymentRef,
                $depositDate,
                $claims[0]->getPaymentInfo()->getPayer(),
                $providerAdjustments,
                $io
            );
        }

        $this->payments->create($paymentRef, $claims, $providerAdjustments);
    }

    private static function moveProcessedPayment(string $file): void
    {
        $fileSystem = new Filesystem();

        if (!$fileSystem->exists(self::PROCESSED_PAYMENTS_PATH)) {
            $fileSystem->mkdir(self::PROCESSED_PAYMENTS_PATH);
        }

        $fileSystem->rename($file, self::PROCESSED_PAYMENTS_PATH . '/' . basename($file));
    }
}
