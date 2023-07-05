<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustmentType;
use PossiblePromise\QbHealthcare\Exception\PaymentCreationException;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPLine;
use Symfony\Component\Console\Style\SymfonyStyle;

trait PaymentCreateTrait
{
    private static function validateChargeAdjustments(
        Charge $charge,
        string $contractualAdjustment,
        ?string $coinsurance,
        string $chargeBilled,
        string $chargePaid
    ): void {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $expectedContractualAdjustment = bcsub(
            $charge->getBilledAmount(),
            $charge->getContractAmount(),
            2
        );

        if (bccomp($contractualAdjustment, $expectedContractualAdjustment, 2) !== 0) {
            throw new PaymentCreationException(
                sprintf(
                    'This line includes a contractual adjustment of %s, but %s was expected.',
                    $fmt->formatCurrency((float) $contractualAdjustment, 'USD'),
                    $fmt->formatCurrency((float) $expectedContractualAdjustment, 'USD')
                )
            );
        }

        $total = bcsub($chargeBilled, $contractualAdjustment, 2);
        if ($coinsurance !== null) {
            $total = bcsub($total, $coinsurance, 2);
        }

        if (bccomp($total, $chargePaid, 2) !== 0) {
            throw new PaymentCreationException(
                sprintf(
                    'Adjustments add up to %s, but %s was expected.',
                    $fmt->formatCurrency((float) bcsub($chargeBilled, $total, 2), 'USD'),
                    $fmt->formatCurrency((float) bcsub($chargeBilled, $chargePaid, 2), 'USD')
                )
            );
        }
    }

    private static function showPaidClaims(
        array $claims,
        string $providerAdjustmentAmount,
        SymfonyStyle $io
    ): void {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $table = [];
        $paymentTotal = '0.00';

        /** @var Claim $claim */
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

        if (bccomp($providerAdjustmentAmount, '0.00', 2) === 1) {
            $table[] = [
                'Interest owed',
                '',
                '',
                '',
                $fmt->formatCurrency((float) $providerAdjustmentAmount, 'USD'),
            ];

            $paymentTotal = bcadd($paymentTotal, $providerAdjustmentAmount, 2);
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

                if ($line->SalesItemLineDetail->ItemRef === $this->qb->getActiveCompany(
                )->contractualAdjustmentItem) {
                    $creditedContractualAdjustments = bcadd(
                        $creditedContractualAdjustments,
                        (string) $line->Amount,
                        2
                    );
                } elseif ($line->SalesItemLineDetail->ItemRef === $this->qb->getActiveCompany(
                )->coinsuranceItem) {
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
            throw new PaymentCreationException(
                sprintf(
                    'The contractual adjustment is %s, but %s of contractual adjustments were credited.',
                    $fmt->formatCurrency((float) $contractualAdjustments, 'USD'),
                    $fmt->formatCurrency((float) $creditedContractualAdjustments, 'USD')
                )
            );
        }

        if (bccomp($coinsurance, $creditedCoinsurance, 2) === -1) {
            throw new PaymentCreationException(
                sprintf(
                    'A coinsurance of %s was expected, but %s was credited.',
                    $fmt->formatCurrency((float) $coinsurance, 'USD'),
                    $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
                )
            );
        }
        if (bccomp($coinsurance, $creditedCoinsurance, 2) === 1) {
            if (bccomp($creditedCoinsurance, '0.00', 2) !== 0) {
                throw new PaymentCreationException(
                    sprintf(
                        'A coinsurance of %s is unexpected, but %s has already been credited. We cannot yet handle this condition.',
                        $fmt->formatCurrency((float) $coinsurance, 'USD'),
                        $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
                    )
                );
            }

            $charges = $this->charges->findByClaim($claim);
            $creditMemo = $this->creditMemos->createCoinsuranceCredit($claim, $charges);
            $io->text(
                sprintf(
                    'Created credit memo %s for %s',
                    $creditMemo->DocNumber,
                    $fmt->formatCurrency((float) $creditMemo->TotalAmt, 'USD')
                )
            );

            $claim->addQbCreditMemo($creditMemo);
            $this->claims->save($claim);

            $creditedCoinsurance = bcadd($creditedCoinsurance, (string) $creditMemo->TotalAmt, 2);
            if (bccomp($coinsurance, $creditedCoinsurance, 2) !== 0) {
                throw new PaymentCreationException(
                    sprintf(
                        'Expected coinsurance of %s, but got %s.',
                        $fmt->formatCurrency((float) $coinsurance, 'USD'),
                        $fmt->formatCurrency((float) $creditedCoinsurance, 'USD')
                    )
                );
            }

            $creditedAdjustments = bcadd($creditedAdjustments, (string) $creditMemo->TotalAmt, 2);
        } else {
            $io->text(sprintf('No credit memos to create for %s.', $claim->getBillingId()));
        }

        if (bccomp($totalAdjustments, $creditedAdjustments, 2) !== 0) {
            throw new PaymentCreationException(
                sprintf(
                    'Expected total adjustments of %s, but adjustments of %s were encountered.',
                    $fmt->formatCurrency((float) $totalAdjustments, 'USD'),
                    $fmt->formatCurrency((float) $creditedAdjustments, 'USD')
                )
            );
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

    /**
     * @return ProviderAdjustment[]
     */
    private function createProviderAdjustments(
        string $paymentRef,
        \DateTimeInterface $paymentDate,
        Payer $payer,
        string $providerAdjustmentAmount,
        SymfonyStyle $io
    ): array {
        $invoice = $this->invoices->createFromProviderAdjustment(
            $paymentRef,
            $paymentDate,
            $payer,
            $providerAdjustmentAmount
        );
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $io->text(
            sprintf(
                'Created invoice %s for %s of interest.',
                $invoice->DocNumber,
                $fmt->formatCurrency((float) $invoice->TotalAmt, 'USD')
            )
        );

        $adjustment = new ProviderAdjustment(
            $invoice,
            ProviderAdjustmentType::interest,
        );

        return [$adjustment];
    }

    private function syncToQb(
        string $paymentRef,
        \DateTimeInterface $depositDate,
        array $claims,
        string $providerAdjustmentAmount,
        SymfonyStyle $io
    ): void {
        $this->validateCoinsuranceItemSet($io);

        // Verify the credits in QB
        foreach ($claims as $claim) {
            $this->verifyQbInvoice($claim);
            $this->syncAdjustmentsToQb($claim, $io);
        }

        $adjustments = [];
        if (bccomp($providerAdjustmentAmount, '0.00', 2) !== 0) {
            $this->validateInterestItemSet($io);
            $adjustments = $this->createProviderAdjustments(
                $paymentRef,
                $depositDate,
                $claims[0]->getPaymentInfo()->getPayer(),
                $providerAdjustmentAmount,
                $io
            );
        }

        $this->payments->create($paymentRef, $claims, $adjustments);
    }
}
