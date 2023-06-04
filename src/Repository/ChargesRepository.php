<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Model\BSONIterator;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\ClaimStatus;
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\ValueObject\ChargeLine;
use PossiblePromise\QbHealthcare\ValueObject\ChargesImported;
use PossiblePromise\QbHealthcare\ValueObject\ClaimSummary;

final class ChargesRepository
{
    private Collection $charges;

    public function __construct(MongoClient $client, private readonly PayersRepository $payers)
    {
        $this->charges = $client->getDatabase()->charges;
    }

    /**
     * @param ChargeLine[] $lines
     */
    public function import(array $lines): ChargesImported
    {
        $imported = new ChargesImported();

        foreach ($lines as $line) {
            $payer = $this->payers->findOneByNameAndService($line->primaryPayer, $line->billingCode);
            if ($payer === null) {
                throw new \UnexpectedValueException('No payer found');
            }

            $service = $payer->getServices()[0];

            $paymentInfo = new PaymentInfo(
                payer: $payer,
                billedDate: $line->primaryBilledDate,
                paymentDate: $line->primaryPaymentDate,
                payment: $line->primaryPayment,
                paymentRef: $line->primaryPaymentRef,
                copay: $line->copay,
                coinsurance: $line->coinsurance,
                deductible: $line->deductible,
                postedDate: $line->primaryPostedDate
            );

            $charge = new Charge(
                chargeLine: $line->chargeLine,
                serviceDate: $line->dateOfService,
                clientName: $line->clientName,
                service: $service,
                billedAmount: $line->billedAmount,
                contractAmount: $line->contractAmount,
                billedUnits: $line->billedUnits,
                primaryPaymentInfo: $paymentInfo,
                payerBalance: $line->payerBalance
            );

            $result = $this->charges->updateOne(
                ['_id' => $charge->getChargeLine(), 'status' => ClaimStatus::pending],
                ['$set' => $charge],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
            $imported->modified += $result->getModifiedCount() ?? 0;
        }

        return $imported;
    }

    /**
     * @param numeric-string[] $charges
     */
    public function getSummary(
        string $client,
        string $payer,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        array $charges = []
    ): ?ClaimSummary {
        /** @var BSONIterator $result */
        $result = $this->charges->aggregate([
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            ['$group' => [
                '_id' => '$primaryPaymentInfo.payer.name',
                'billedAmount' => ['$sum' => '$billedAmount'],
                'contractAmount' => ['$sum' => '$contractAmount'],
                'coinsurance' => ['$sum' => '$primaryPaymentInfo.coinsurance'],
            ]],
        ]);

        $result->next();

        if (!$result->valid()) {
            return null;
        }

        /** @var array{_id: string, billedAmount: Decimal128, contractAmount: Decimal128, coinsurance: Decimal128} $summary */
        $summary = $result->current();

        /** @var numeric-string $billedAmount */
        $billedAmount = (string) $summary['billedAmount'];

        /** @var numeric-string $contractAmount */
        $contractAmount = (string) $summary['contractAmount'];

        /** @var numeric-string $coinsurance */
        $coinsurance = (string) $summary['coinsurance'];

        return new ClaimSummary($summary['_id'], $billedAmount, $contractAmount, $coinsurance);
    }

    /**
     * @param numeric-string[] $charges
     *
     * @return Charge[]
     */
    public function getClaimCharges(string $client, string $payer, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, array $charges = []): array
    {
        /** @var BSONIterator $result */
        $result = $this->charges->aggregate([
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            ['$sort' => ['serviceDate' => 1, '_id' => 1]],
        ]);

        $charges = [];

        /** @var Charge $charge */
        foreach ($result as $charge) {
            $charges[] = $charge;
        }

        return $charges;
    }

    /**
     * @param numeric-string[] $charges
     */
    public function processClaim(
        string $fileId,
        string $claimId,
        string $client,
        string $payer,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $qbInvoiceNumber,
        string $qbCreditNumber,
        array $charges = []
    ): void {
        $this->charges->updateMany(
            self::getClaimQuery($client, $payer, $startDate, $endDate, $charges),
            ['$set' => [
                'fileId' => $fileId,
                'claimId' => $claimId,
                'status' => 'processed',
                'qbInvoiceNumber' => $qbInvoiceNumber,
                'qbCreditMemoNumber' => $qbCreditNumber,
            ]]
        );
    }

    /**
     * @return Charge[]
     */
    public function findByPaymentRef(string $paymentRef): array
    {
        $result = $this->charges->aggregate([
            ['$match' => self::getPaymentQuery($paymentRef)],
            ['$sort' => ['serviceDate' => 1]],
        ]);

        $charges = [];
        /** @var Charge $charge */
        foreach ($result as $charge) {
            $charges[] = $charge;
        }

        return $charges;
    }

    public function createPayment(string $paymentRef): void
    {
        $this->charges->updateMany(
            self::getPaymentQuery($paymentRef),
            ['$set' => ['status' => 'paid']]
        );
    }

    public function getInvoiceTotal(string $invoice): string
    {
        /** @var BSONIterator $result */
        $result = $this->charges->aggregate([
            ['$match' => ['qbInvoiceNumber' => $invoice]],
            ['$group' => [
                '_id' => null,
                'total' => ['$sum' => '$billedAmount'],
            ]],
        ]);

        $result->next();

        /** @var array{total: Decimal128} $total */
        $total = $result->current();

        return (string) $total['total'];
    }

    public function getCreditMemoTotal(string $creditMemo): string
    {
        /** @var BSONIterator $result */
        $result = $this->charges->aggregate([
            ['$match' => ['qbCreditMemoNumber' => $creditMemo]],
            ['$group' => [
                '_id' => null,
                'total' => ['$sum' => [
                    '$add' => [
                        ['$subtract' => ['$billedAmount', '$contractAmount']],
                        '$primaryPaymentInfo.coinsurance'],
                ]],
            ]],
        ]);

        $result->next();

        /** @var array{total: Decimal128} $total */
        $total = $result->current();

        return (string) $total['total'];
    }

    public function findClient(string $lastName, string $firstName): ?string
    {
        /** @var BSONIterator $result */
        $result = $this->charges->aggregate([
            ['$match' => [
                'clientName' => [
                    '$regex' => new Regex('' . $lastName . ',? ' . $firstName . '', 'i'),
                ],
            ]],
            ['$project' => [
                'clientName' => true,
            ]],
        ]);

        $result->next();

        if (!$result->valid()) {
            return null;
        }

        /** @var array{clientName: string} $record */
        $record = $result->current();

        return $record['clientName'];
    }

    /**
     * @param numeric-string[] $charges
     */
    private static function getClaimQuery(
        string $client,
        string $payer,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        array $charges = []
    ): array {
        if ($charges) {
            $query = [
                '_id' => ['$in' => $charges],
            ];
        } else {
            $query = [
                'clientName' => $client,
                'primaryPaymentInfo.payer._id' => $payer,
                'serviceDate' => [
                    '$gte' => new UTCDateTime($startDate),
                    '$lte' => new UTCDateTime($endDate),
                ],
                'status' => 'pending',
            ];
        }

        return $query;
    }

    private static function getPaymentQuery(string $paymentRef): array
    {
        return [
            'primaryPaymentInfo.paymentRef' => $paymentRef,
            'status' => 'processed',
        ];
    }
}
