<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustmentType;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\CreditMemo;
use QuickBooksOnline\API\Facades\Line;

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

        $creditMemo = $this->createCreditMemoObject(
            $charges[0]->getPrimaryPaymentInfo()->getPayer()->getQbCustomerId(),
            $charges[0]->getPrimaryPaymentInfo()->getBilledDate(),
            $billingId,
            $lines
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
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
                serviceDate: $charge->getServiceDate(),
                itemId: $this->qb->getActiveCompany()->coinsuranceItem,
                description: $charge->getChargeLine(),
                quantity: 1,
                unitPrice: $charge->getPrimaryPaymentInfo()->getCoinsurance()
            );
        }

        $creditMemo = $this->createCreditMemoObject(
            $claim->getPaymentInfo()->getPayer()->getQbCustomerId(),
            $claim->getPaymentInfo()->getPaymentDate(),
            $claim->getBillingId(),
            $lines
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->dataService->add($creditMemo);
    }

    public function get(string $creditMemoId): IPPCreditMemo
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
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

    public function createFromProviderAdjustment(
        string $paymentRef,
        \DateTimeInterface $paymentDate,
        Payer $payer,
        ProviderAdjustment $providerAdjustment
    ): IPPCreditMemo {
        switch ($providerAdjustment->getType()) {
            case ProviderAdjustmentType::origination_fee:
                $itemId = $this->qb->getActiveCompany()->originationFeeItem;
                if ($itemId === null) {
                    throw new \RuntimeException('The item to use for origination fees has not been set.');
                }
                break;

            default:
                throw new \InvalidArgumentException('Cannot handle type: ' . $providerAdjustment->getType()->value);
        }

        $line = self::createCreditMemoLine(
            lineNum: 1,
            serviceDate: null,
            itemId: $itemId,
            description: null,
            quantity: 1,
            unitPrice: bcmul($providerAdjustment->getAmount(), '-1', 2)
        );

        $creditMemo = $this->createCreditMemoObject(
            $payer->getQbCustomerId(),
            $paymentDate,
            $paymentRef,
            [$line]
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->getDataService()->add($creditMemo);
    }

    private function createCreditMemoLineFromCharge(int $lineNum, Charge $charge): array
    {
        $service = $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0];
        $quantity = $charge->getBilledUnits();
        $unitPrice = bcsub($service->getRate(), $service->getContractRate(), 2);
        \assert(
            bccomp(
                bcmul((string) $quantity, $unitPrice, 2),
                bcsub($charge->getBilledAmount(), $charge->getContractAmount(), 2),
                2
            ) === 0
        );

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return self::createCreditMemoLine(
            $lineNum,
            $charge->getServiceDate(),
            $this->qb->getActiveCompany()->contractualAdjustmentItem,
            $charge->getChargeLine(),
            $quantity,
            $unitPrice
        );
    }

    private static function createCreditMemoLine(int $lineNum, ?\DateTimeInterface $serviceDate, string $itemId, ?string $description, int $quantity, string $unitPrice): IPPLine
    {
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

    /**
     * @param IPPLine[] $lines
     */
    private function createCreditMemoObject(
        string $customerRef,
        \DateTimeInterface $date,
        string $memo,
        array $lines
    ): IPPCreditMemo {
        return CreditMemo::create([
            'AutoDocNumber' => true,
            'Line' => $lines,
            'CustomerRef' => [
                'value' => $customerRef,
            ],
            'TxnDate' => $date->format('Y-m-d'),
            'PrivateNote' => $memo,
        ]);
    }
}
