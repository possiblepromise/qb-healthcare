<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payment as PaymentEntity;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Facades\Payment;

final class PaymentsRepository
{
    use QbApiTrait;

    private Collection $payments;

    public function __construct(
        private InvoicesRepository $invoices,
        private CreditMemosRepository $creditMemos,
        private ClaimsRepository $claims,
        QuickBooks $qb,
        MongoClient $client
    ) {
        $this->qb = $qb;
        $this->payments = $client->getDatabase()->selectCollection('paymentData');
    }

    /**
     * @param Claim[] $claims
     */
    public function create(string $paymentRef, array $claims): IPPPayment
    {
        if (empty($claims)) {
            throw new \RuntimeException('At least one claim must be passed to create a payment.');
        }

        $lines = [];
        $total = '0.00';
        $claimIds = [];

        foreach ($claims as $claim) {
            $total = bcadd($total, $claim->getPaymentInfo()->getPayment(), 2);
            $claimIds[] = $claim->getId();

            $lines[] = self::createPaymentLineFromInvoice($this->invoices->get($claim->getQbInvoiceId()));

            foreach ($claim->getQbCreditMemoIds() as $creditMemoId) {
                $lines[] = self::createPaymentLineFromCreditMemo($this->creditMemos->get($creditMemoId));
            }
        }

        $payment = Payment::create([
            'TotalAmt' => $total,
            'CustomerRef' => [
                'value' => $claims[0]->getPaymentInfo()->getPayer()->getQbCustomerId(),
            ],
            'PrivateNote' => \strlen($paymentRef) > 21 ? $paymentRef : null,
            'Line' => $lines,
            'TxnDate' => $claims[0]->getPaymentInfo()->getPaymentDate()->format('Y-m-d'),
            'PaymentRefNum' => substr($paymentRef, 0, 21),
        ]);

        /** @var IPPPayment $payment */
        $payment = $this->getDataService()->add($payment);

        $paymentEntity = new PaymentEntity($paymentRef, $payment->Id, $claimIds);
        $paymentEntity->setQbCompanyId($this->qb->getActiveCompany()->realmId);
        $this->payments->insertOne($paymentEntity);
        $this->claims->markPaid($paymentRef);

        return $payment;
    }

    private static function createPaymentLineFromInvoice(IPPInvoice $invoice): array
    {
        return self::createPaymentLine(
            'Invoice',
            (string) $invoice->Id,
            (float) $invoice->TotalAmt
        );
    }

    private static function createPaymentLineFromCreditMemo(IPPCreditMemo $creditMemo): array
    {
        return self::createPaymentLine(
            'CreditMemo',
            (string) $creditMemo->Id,
            (float) $creditMemo->TotalAmt
        );
    }

    private static function createPaymentLine(
        string $txnType,
        string $txnId,
        float $amount
    ): array {
        return [
            'Amount' => $amount,
            'LinkedTxn' => [
                [
                    'TxnId' => $txnId,
                    'TxnType' => $txnType,
                ],
            ],
        ];
    }
}
