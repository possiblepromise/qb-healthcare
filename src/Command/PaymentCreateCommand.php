<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Era835Reader;
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
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPLine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'payment:create',
    description: 'Create a payment.'
)]
final class PaymentCreateCommand extends Command
{
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

        $era835 = new Era835Reader($file);

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $code = $era835->read('BPR01');
        if ($code !== 'I') {
            $io->error('This ERA 835 does not contain remittance information.');

            return Command::INVALID;
        }

        $paymentAmount = $era835->read('BPR02');
        if ($era835->read('BPR03') !== 'C') {
            $io->error('This ERA 835 file does not represent a credit.');

            return Command::FAILURE;
        }

        $paymentType = $era835->read('BPR04');
        Assert::oneOf($paymentType, ['ACH', 'CHK'], 'Invalid payment type encountered');

        $depositDate = \DateTimeImmutable::createFromFormat('Ymd', $era835->read('BPR16'))->modify('00:00:00');

        $paymentRef = $era835->read('TRN02');
        $payerName = $era835->read('N102');

        $message = sprintf(
            'Did you receive a %s from %s for %s on %s?',
            $paymentType === 'ACH' ? 'deposit' : 'check',
            $payerName,
            $fmt->formatCurrency((float) $paymentAmount, 'USD'),
            $depositDate->format('Y-m-d')
        );
        if (!$io->confirm($message)) {
            return Command::INVALID;
        }

        $paymentTotal = '0.00';
        $claims = [];

        try {
            while (bccomp($paymentTotal, $paymentAmount, 2) === -1) {
                $claim = $this->processClaim($paymentRef, $depositDate, $era835);

                $paymentTotal = bcadd($paymentTotal, $claim->getPaymentInfo()->getPayment(), 2);
                $claims[] = $claim;
            }
        } catch (PaymentCreationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (bccomp($paymentTotal, $paymentAmount, 2) === 1) {
            $io->error(sprintf(
                'Claims add up to %s, but %s was expected.',
                $fmt->formatCurrency((float) $paymentTotal, 'USD'),
                $fmt->formatCurrency((float) $paymentAmount, 'USD')
            ));

            return Command::FAILURE;
        }

        self::showPaidClaims($claims, $paymentTotal, $io);

        if (!$io->confirm('Continue?', false)) {
            return Command::SUCCESS;
        }

        $this->validateCoinsuranceItemSet($io);

        // Verify the credits in QB
        try {
            /** @var Claim $claim */
            foreach ($claims as $claim) {
                $this->verifyQbInvoice($claim);
                $this->syncAdjustmentsToQb($claim, $io);
            }
        } catch (PaymentCreationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->payments->create($paymentRef, $claims);

        $io->success(sprintf('Payment %s has been processed successfully.', $paymentRef));

        return Command::SUCCESS;
    }

    private function processClaim(string $paymentRef, \DateTimeImmutable $paymentDate, Era835Reader $era835): Claim
    {
        $amountClaimed = $era835->read('CLP03');
        $amountPaid = $era835->read('CLP04');
        $patientResponsibility = $era835->read('CLP05');
        $lastName = $era835->read('NM103');
        $firstName = $era835->read('NM104');

        // Loop through services
        $claimTotal = '0.00';
        $claimPatientResponsibility = '0.00';
        $claimCharges = new FilterableArray();

        while (bccomp($claimTotal, $amountPaid, 2) === -1) {
            $charge = $this->processCharge(
                $paymentRef,
                $paymentDate,
                $lastName,
                $firstName,
                $era835
            );

            $claimCharges->add($charge);
            $claimTotal = bcadd($claimTotal, $charge->getPrimaryPaymentInfo()->getPayment(), 2);
            $claimPatientResponsibility = bcadd($claimPatientResponsibility, $charge->getPrimaryPaymentInfo()->getCoinsurance(), 2);
        }

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        if (bccomp($claimTotal, $amountPaid, 2) === 1) {
            throw new PaymentCreationException(sprintf(
                'Charges add up to %s, but %s was expected.',
                $fmt->formatCurrency((float) $claimTotal, 'USD'),
                $fmt->formatCurrency((float) $amountPaid, 'USD')
            ));
        }
        if (bccomp($claimPatientResponsibility, $patientResponsibility, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'Patient responsibility should be %s, but %s received.',
                $fmt->formatCurrency((float) $patientResponsibility, 'USD'),
                $fmt->formatCurrency((float) $claimPatientResponsibility, 'USD')
            ));
        }

        $claim = $this->claims->findOneByCharges($claimCharges);

        if ($claim === null) {
            throw new PaymentCreationException('No claim found for the matched charges.');
        }
        if (bccomp($amountClaimed, $claim->getBilledAmount(), 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'Total billed on the claim was %s, but %s was expected.',
                $fmt->formatCurrency((float) $claim->getBilledAmount(), 'USD'),
                $fmt->formatCurrency((float) $amountClaimed, 'USD')
            ));
        }
        if (bccomp($amountPaid, $claim->getPaymentInfo()->getPayment(), 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'Total payments on the claim are %s, but %s was expected.',
                $fmt->formatCurrency((float) $claim->getPaymentInfo()->getPayment(), 'USD'),
                $fmt->formatCurrency((float) $amountPaid, 'USD')
            ));
        }

        return $claim;
    }

    private function processCharge(
        string $paymentRef,
        \DateTimeImmutable $paymentDate,
        ?string $lastName,
        ?string $firstName,
        Era835Reader $era835
    ): Charge {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $billingCode = $era835->read('SVC01-2');
        $chargeBilled = $era835->read('SVC02');
        $chargePaid = $era835->read('SVC03');
        $chargeUnits = (int) $era835->read('SVC05');
        $serviceDate = \DateTimeImmutable::createFromFormat('Ymd', $era835->read('DTM02'))->modify('00:00:00');

        $charges = $this->charges->findBySvcData(
            billingCode: $billingCode,
            billedAmount: $chargeBilled,
            units: $chargeUnits,
            serviceDate: $serviceDate,
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

        [$contractualAdjustment, $coinsurance] = $this->processAdjustments($era835);

        $expectedContractualAdjustment = bcsub($charge->getBilledAmount(), $charge->getContractAmount(), 2);

        if (bccomp($contractualAdjustment, $expectedContractualAdjustment, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'This line includes a contractual adjustment of %s, but %s was expected.',
                $fmt->formatCurrency((float) $contractualAdjustment, 'USD'),
                $fmt->formatCurrency((float) $expectedContractualAdjustment, 'USD')
            ));
        }

        $total = bcsub($chargeBilled, $contractualAdjustment, 2);
        if ($coinsurance !== null) {
            $total = bcsub($total, $coinsurance, 2);
        }

        if (bccomp($total, $chargePaid, 2) !== 0) {
            throw new PaymentCreationException(sprintf(
                'Adjustments add up to %s, but %s was expected.',
                $fmt->formatCurrency((float) bcsub($chargeBilled, $total, 2), 'USD'),
                $fmt->formatCurrency((float) bcsub($chargeBilled, $chargePaid, 2), 'USD')
            ));
        }

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

    private function processAdjustments(Era835Reader $era835): array
    {
        $contractualAdjustment = null;
        $coinsurance = null;

        do {
            $adjustmentType = $era835->read('CAS01');
            $code = $era835->read('CAS02');
            $adjustmentAmount = $era835->read('CAS03');

            if ($adjustmentType === 'CO' && $code === '45') {
                $contractualAdjustment = $adjustmentAmount;
            } elseif ($adjustmentType === 'PR' && $code === '2') {
                $coinsurance = $adjustmentAmount;
            } else {
                throw new PaymentCreationException(
                    sprintf(
                        'Encountered unexpected adjustment: %s with code %s',
                        $adjustmentType,
                        $code
                    )
                );
            }

            $segmentType = $era835->next();
        } while ($segmentType === 'CAS');

        if ($contractualAdjustment === null) {
            throw new PaymentCreationException('Cannot find contractual adjustment.');
        }

        return [$contractualAdjustment, $coinsurance];
    }

    private static function showPaidClaims(
        array $claims,
        string $paymentTotal,
        SymfonyStyle $io
    ): void {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $table = [];

        /** @var Claim $claim */
        foreach ($claims as $claim) {
            $table[] = [
                $claim->getBillingId(),
                $claim->getPaymentInfo()->getBilledDate()->format('Y-m-d'),
                $claim->getStartDate()->format('n/j') . '-' . $claim->getEndDate()->format('n/j'),
                $claim->getClientName(),
                $fmt->formatCurrency((float) $claim->getPaymentInfo()->getPayment(), 'USD'),
            ];
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

    private function verifyQbInvoice(Claim $claim): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $invoice = $this->invoices->get($claim->getQbInvoiceId());
        if (bccomp((string) $invoice->TotalAmt, $claim->getBilledAmount(), 2) !== 0) {
            throw new PaymentCreationException(
                sprintf(
                    'QuickBooks invoice %s is for the amount of %s, but %s was expected.',
                    $invoice->DocNumber,
                    $fmt->formatCurrency((float) $invoice->TotalAmt, 'USD'),
                    $fmt->formatCurrency((float) $claim->getBilledAmount(), 'USD')
                )
            );
        }
    }

    private function syncAdjustmentsToQb(Claim $claim, SymfonyStyle $io): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $creditMemoIds = $claim->getQbCreditMemoIds();
        $totalAdjustments = bcsub($claim->getBilledAmount(), $claim->getPaymentInfo()->getPayment(), 2);
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

                    throw new PaymentCreationException("Found unexpected adjustment using the item: {$item->Name}");
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
}
