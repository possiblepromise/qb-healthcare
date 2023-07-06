<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Line;

final class InvoicesRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @param Charge[] $charges
     */
    public function createFromCharges(string $billingId, array $charges): IPPInvoice
    {
        if (empty($charges)) {
            throw new \RuntimeException('At least one charge must be passed to create an invoice.');
        }
        if ($this->qb->getActiveCompany()->paymentTerm === null) {
            throw new \RuntimeException('A default payment term has not been set.');
        }

        $lines = [];

        foreach ($charges as $lineNum => $charge) {
            $lines[] = self::createInvoiceLineFromCharge($lineNum + 1, $charge);
        }

        $invoice = $this->createInvoiceObject(
            customerRef: $charges[0]->getPrimaryPaymentInfo()->getPayer()->getQbCustomerId(),
            date: $charges[0]->getPrimaryPaymentInfo()->getBilledDate(),
            memo: $billingId,
            lines: $lines
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->getDataService()->add($invoice);
    }

    public function createFromProviderAdjustment(string $paymentRef, \DateTimeInterface $paymentDate, Payer $payer, string $adjustmentAmount): IPPInvoice
    {
        if ($this->qb->getActiveCompany()->interestItem === null) {
            throw new \RuntimeException('The item to use for interest payments has not been set.');
        }

        $line = self::createInvoiceLine(
            lineNum: 1,
            serviceDate: null,
            itemId: $this->qb->getActiveCompany()->interestItem,
            description: null,
            quantity: 1,
            unitPrice: $adjustmentAmount
        );

        $invoice = $this->createInvoiceObject(
            $payer->getQbCustomerId(),
            $paymentDate,
            $paymentRef,
            [$line]
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->getDataService()->add($invoice);
    }

    public function delete(IPPInvoice $invoice): void
    {
        $this->getDataService()->Delete($invoice);
    }

    public function get(string $invoiceId): IPPInvoice
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->getDataService()->FindById('Invoice', $invoiceId);
    }

    public function setMemo(IPPInvoice $invoice, string $memo): void
    {
        $updatedInvoice = Invoice::update($invoice, [
            'sparse' => true,
            'PrivateNote' => $memo,
        ]);

        $this->getDataService()->Update($updatedInvoice);
    }

    private static function createInvoiceLineFromCharge(int $lineNum, Charge $charge): IPPLine
    {
        $service = $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0];

        return self::createInvoiceLine(
            lineNum: $lineNum,
            serviceDate: $charge->getServiceDate(),
            itemId: $service->getQbItemId(),
            description: $charge->getChargeLine(),
            quantity: $charge->getBilledUnits(),
            unitPrice: $service->getRate()
        );
    }

    private static function createInvoiceLine(
        int $lineNum,
        ?\DateTimeInterface $serviceDate,
        string $itemId,
        ?string $description,
        int $quantity,
        string $unitPrice
    ): IPPLine {
        return Line::create([
            'LineNum' => (string) $lineNum,
            'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => [
                'ItemRef' => [
                    'value' => $itemId,
                ],
                'ServiceDate' => $serviceDate?->format('Y-m-d'),
                'Qty' => $quantity,
                'UnitPrice' => $unitPrice,
            ],
            'Amount' => bcmul($unitPrice, (string) $quantity, 2),
            'Description' => $description,
        ]);
    }

    private function createInvoiceObject(
        ?string $customerRef,
        ?\DateTimeInterface $date,
        string $memo,
        array $lines
    ): mixed {
        return Invoice::create([
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $customerRef,
            ],
            'TxnDate' => $date->format('Y-m-d'),
            'SalesTermRef' => [
                'value' => $this->qb->getActiveCompany()->paymentTerm,
            ],
            'PrivateNote' => $memo,
            'AutoDocNumber' => true,
        ]);
    }
}
