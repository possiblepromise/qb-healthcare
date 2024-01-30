<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payment as PaymentEntity;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustmentType;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Facades\Line;
use QuickBooksOnline\API\Facades\Payment;

final class PaymentsRepository
{
    use QbApiTrait;

    private Collection $paymentData;
    private Collection $payments;

    public function __construct(
        private InvoicesRepository $invoices,
        private CreditMemosRepository $creditMemos,
        private ClaimsRepository $claims,
        QuickBooks $qb,
        MongoClient $client
    ) {
        $this->qb = $qb;
        $this->paymentData = $client->getDatabase()->selectCollection('paymentData');
        $this->payments = $client->getDatabase()->selectCollection('payments');
    }

    /**
     * @param Claim[]              $claims
     * @param ProviderAdjustment[] $providerAdjustments
     */
    public function create(string $paymentRef, array $claims, array $providerAdjustments = []): IPPPayment
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
                $creditMemo = $this->creditMemos->get($creditMemoId);
                if ($creditMemo->status !== 'Voided') {
                    $lines[] = self::createPaymentLineFromCreditMemo($creditMemo);
                }
            }
        }

        foreach ($providerAdjustments as $providerAdjustment) {
            $total = bcadd($total, $providerAdjustment->getAmount(), 2);

            $amount = $providerAdjustment->getAmount();
            if (bccomp($amount, '0', 2) === -1) {
                $amount = bcmul($amount, '-1', 2);
            }

            $lines[] = match ($providerAdjustment->getType()) {
                ProviderAdjustmentType::interest => self::createPaymentLine('Invoice', $providerAdjustment->getQbEntityId(), $amount),
                ProviderAdjustmentType::origination_fee => self::createPaymentLine('CreditMemo', $providerAdjustment->getQbEntityId(), $amount),
            };
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

        $paymentEntity = new PaymentEntity($paymentRef, (string) $payment->Id, $claimIds, $providerAdjustments);
        $paymentEntity->setQbCompanyId($this->qb->getActiveCompany()->realmId);
        $this->paymentData->insertOne($paymentEntity);
        $this->claims->markPaid($paymentRef);

        return $payment;
    }

    public function get(string $paymentRef): ?PaymentEntity
    {
        return $this->payments->findOne(['_id' => $paymentRef]);
    }

    private static function createPaymentLineFromInvoice(IPPInvoice $invoice): IPPLine
    {
        return self::createPaymentLine(
            'Invoice',
            (string) $invoice->Id,
            (string) $invoice->TotalAmt
        );
    }

    private static function createPaymentLineFromCreditMemo(IPPCreditMemo $creditMemo): IPPLine
    {
        return self::createPaymentLine(
            'CreditMemo',
            (string) $creditMemo->Id,
            (string) $creditMemo->TotalAmt
        );
    }

    private static function createPaymentLine(
        string $txnType,
        string $txnId,
        string $amount
    ): IPPLine {
        return Line::create([
            'Amount' => $amount,
            'LinkedTxn' => [
                [
                    'TxnId' => $txnId,
                    'TxnType' => $txnType,
                ],
            ],
        ]);
    }
}
