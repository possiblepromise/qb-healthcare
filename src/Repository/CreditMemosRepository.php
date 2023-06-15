<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Facades\CreditMemo;

final class CreditMemosRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @param Charge[] $charges
     */
    public function createContractualAdjustmentCreditFromCharges(string $claimId, array $charges): IPPCreditMemo
    {
        $lines = [];

        foreach ($charges as $lineNum => $charge) {
            $lines[] = $this->createCreditMemoLineFromCharge($lineNum + 1, $charge);
        }

        $creditMemo = CreditMemo::create([
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $charges[0]->getPrimaryPaymentInfo()->getPayer()->getQbCustomerId(),
            ],
            'TxnDate' => $charges[0]->getPrimaryPaymentInfo()->getBilledDate()->format('Y-m-d'),
            'PrivateNote' => $claimId,
        ]);

        return $this->getDataService()->add($creditMemo);
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
