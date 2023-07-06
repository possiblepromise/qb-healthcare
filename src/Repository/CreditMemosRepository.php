<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\CreditMemo;

final class CreditMemosRepository
{
    use QbApiTrait;

    private DataService $dataService;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
        $this->dataService = $this->getDataService();
    }

    /**
     * @param Charge[] $charges
     */
    public function createContractualAdjustmentCreditFromCharges(string $billingId, array $charges): IPPCreditMemo
    {
        $lines = [];

        foreach ($charges as $lineNum => $charge) {
            $lines[] = $this->createCreditMemoLineFromCharge($lineNum + 1, $charge);
        }

        $creditMemo = CreditMemo::create([
            'AutoDocNumber' => true,
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $charges[0]->getPrimaryPaymentInfo()->getPayer()->getQbCustomerId(),
            ],
            'TxnDate' => $charges[0]->getPrimaryPaymentInfo()->getBilledDate()->format('Y-m-d'),
            'PrivateNote' => $billingId,
        ]);

        return $this->dataService->add($creditMemo);
    }

    public function createCoinsuranceCredit(Claim $claim, FilterableArray $charges): IPPCreditMemo
    {
        $lines = [];

        /** @var Charge $charge */
        foreach ($charges as $i => $charge) {
            if (bccomp($charge->getPrimaryPaymentInfo()->getCoinsurance(), '0.00', 2) === 0) {
                continue;
            }

            $lines[] = self::createCreditMemoLine(
                lineNum: $i + 1,
                date: $charge->getServiceDate(),
                item: $this->qb->getActiveCompany()->coinsuranceItem,
                description: $charge->getChargeLine(),
                quantity: 1,
                unitPrice: $charge->getPrimaryPaymentInfo()->getCoinsurance()
            );
        }

        $creditMemo = CreditMemo::create([
            'AutoDocNumber' => true,
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $claim->getPaymentInfo()->getPayer()->getQbCustomerId(),
            ],
            'TxnDate' => $claim->getPaymentInfo()->getPaymentDate()->format('Y-m-d'),
            'PrivateNote' => $claim->getBillingId(),
        ]);

        return $this->dataService->add($creditMemo);
    }

    public function get(string $creditMemoId): IPPCreditMemo
    {
        return $this->dataService->FindById('CreditMemo', $creditMemoId);
    }

    public function setMemo(IPPCreditMemo $creditMemo, string $memo): void
    {
        $updatedCreditMemo = CreditMemo::update($creditMemo, [
            'sparse' => true,
            'PrivateNote' => $memo,
        ]);

        $this->getDataService()->Update($updatedCreditMemo);
    }

    public function delete(IPPCreditMemo $creditMemo): void
    {
        $this->getDataService()->Delete($creditMemo);
    }

    private function createCreditMemoLineFromCharge(int $lineNum, Charge $charge): array
    {
        $service = $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0];
        $quantity = $charge->getBilledUnits();
        $unitPrice = bcsub($service->getRate(), $service->getContractRate(), 2);
        \assert(bccomp(
            bcmul((string) $quantity, $unitPrice, 2),
            bcsub($charge->getBilledAmount(), $charge->getContractAmount(), 2),
            2
        ) === 0);

        return self::createCreditMemoLine(
            $lineNum,
            $charge->getServiceDate(),
            $this->qb->getActiveCompany()->contractualAdjustmentItem,
            $charge->getChargeLine(),
            $quantity,
            $unitPrice
        );
    }

    private static function createCreditMemoLine(int $lineNum, \DateTime $date, string $item, string $description, int $quantity, string $unitPrice): array
    {
        return [
            'LineNum' => (string) $lineNum,
            'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => [
                'ItemRef' => [
                    'value' => $item,
                ],
                'ServiceDate' => $date->format('Y-m-d'),
                'Qty' => $quantity,
                'UnitPrice' => $unitPrice,
            ],
            'Amount' => bcmul((string) $quantity, $unitPrice, 2),
            'Description' => $description,
        ];
    }
}
