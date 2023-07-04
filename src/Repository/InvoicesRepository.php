<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Facades\Invoice;

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
    public function createFromCharges(string $claimId, array $charges): IPPInvoice
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

        $invoice = Invoice::create([
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $charges[0]->getPrimaryPaymentInfo()->getPayer()->getQbCustomerId(),
            ],
            'TxnDate' => $charges[0]->getPrimaryPaymentInfo()->getBilledDate()->format('Y-m-d'),
            'SalesTermRef' => [
                'value' => $this->qb->getActiveCompany()->paymentTerm,
            ],
            'PrivateNote' => $claimId,
        ]);

        return $this->getDataService()->add($invoice);
    }

    public function delete(IPPInvoice $invoice): void
    {
        $this->getDataService()->Delete($invoice);
    }

    public function get(string $invoiceId): IPPInvoice
    {
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

    private static function createInvoiceLineFromCharge(int $lineNum, Charge $charge): array
    {
        $service = $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0];

        return [
            'LineNum' => (string) $lineNum,
            'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => [
                'ItemRef' => [
                    'value' => $service->getQbItemId(),
                ],
                'ServiceDate' => $charge->getServiceDate()->format('Y-m-d'),
                'Qty' => $charge->getBilledUnits(),
                'UnitPrice' => $service->getRate(),
            ],
            'Amount' => $charge->getBilledAmount(),
            'Description' => $charge->getChargeLine(),
        ];
    }
}
