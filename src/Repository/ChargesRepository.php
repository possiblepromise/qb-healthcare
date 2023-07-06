<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use PossiblePromise\QbHealthcare\ValueObject\ChargeLine;
use PossiblePromise\QbHealthcare\ValueObject\ClaimSummary;
use PossiblePromise\QbHealthcare\ValueObject\ImportedRecords;

final class ChargesRepository extends MongoRepository
{
    use QbApiTrait;

    private Collection $charges;
    private Collection $unpaidCharges;

    public function __construct(MongoClient $client, QuickBooks $qb, private PayersRepository $payers)
    {
        $this->charges = $client->getDatabase()->selectCollection('charges');
        $this->unpaidCharges = $client->getDatabase()->selectCollection('unpaidCharges');
        $this->qb = $qb;
    }

    /**
     * @param ChargeLine[] $lines
     */
    public function import(array $lines): ImportedRecords
    {
        $imported = new ImportedRecords();

        foreach ($lines as $line) {
            $payer = $this->payers->findOneByNameAndService($line->primaryPayer, $line->billingCode);
            if ($payer === null) {
                throw new \UnexpectedValueException('No payer found');
            }

            $service = $payer->getServices()[0];

            $paymentInfo = new PaymentInfo(
                payer: $payer,
                billedDate: $line->primaryBilledDate,
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
                payerBalance: $line->billedAmount
            );

            $charge->setQbCompanyId($this->qb->getActiveCompany(true)->realmId);

            $result = $this->charges->updateOne(
                ['_id' => $charge->getChargeLine()],
                ['$setOnInsert' => $charge],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
        }

        return $imported;
    }

    public function getSummary(
        string $client,
        string $payer,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        array $charges = []
    ): ?ClaimSummary {
        /** @var Cursor $result */
        $result = $this->charges->aggregate([
            self::getClaimsLookup(),
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            [
                '$group' => [
                    '_id' => '$primaryPaymentInfo.payer.name',
                    'billedAmount' => ['$sum' => '$billedAmount'],
                    'contractAmount' => ['$sum' => '$contractAmount'],
                    'billedDate' => ['$first' => '$primaryPaymentInfo.billedDate'],
                ],
            ],
        ]);

        $result->next();

        if (!$result->valid()) {
            return null;
        }

        $data = $result->current();

        return new ClaimSummary(
            payer: $data['_id'],
            billedAmount: (string) $data['billedAmount'],
            contractAmount: (string) $data['contractAmount'],
            billedDate: $data['billedDate']->toDateTime()
        );
    }

    /**
     * @param numeric-string[] $charges
     *
     * @return Charge[]
     */
    public function getClaimCharges(string $client, string $payer, \DateTimeInterface $startDate, \DateTimeInterface $endDate, array $charges = []): array
    {
        $result = $this->charges->aggregate([
            self::getClaimsLookup(),
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            ['$sort' => ['serviceDate' => 1, '_id' => 1]],
        ]);

        return self::getArrayFromResult($result);
    }

    public function findClient(string $lastName, string $firstName): ?string
    {
        /** @var Cursor $result */
        $result = $this->charges->aggregate([
            ['$match' => [
                'clientName' => [
                    '$regex' => new Regex("{$lastName},? {$firstName}", 'i'),
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
     * @return Charge[]
     */
    public function findWithoutAppointments(): array
    {
        $result = $this->charges->aggregate([
            ['$lookup' => [
                'from' => 'completedAppointments',
                'localField' => '_id',
                'foreignField' => 'chargeId',
                'as' => 'appointments',
            ]],
            ['$match' => [
                'appointments' => ['$size' => 0],
            ]],
        ]);

        return self::getArrayFromResult($result);
    }

    public function findBySvcData(
        string $billingCode,
        string $billedAmount,
        int $units,
        \DateTimeImmutable $serviceDate,
        string $lastName,
        string $firstName
    ): FilterableArray {
        $result = $this->unpaidCharges->find([
            'billedAmount' => new Decimal128($billedAmount),
            'billedUnits' => $units,
            'serviceDate' => new UTCDateTime($serviceDate),
            'clientName' => [
                '$regex' => new Regex("{$lastName},? {$firstName}", 'i'),
            ],
            'primaryPaymentInfo.payer.services._id' => $billingCode,
        ]);

        return FilterableArray::fromCursor($result);
    }

    public function findByLineItem(
        \DateTimeInterface $serviceDate,
        string $billingCode,
        string $billedAmount,
        string $clientName
    ): FilterableArray {
        $result = $this->unpaidCharges->find([
            'billedAmount' => new Decimal128($billedAmount),
            'serviceDate' => new UTCDateTime($serviceDate),
            'clientName' => $clientName,
            'primaryPaymentInfo.payer.services._id' => $billingCode,
        ]);

        return FilterableArray::fromCursor($result);
    }

    public function findByClaim(Claim $claim): FilterableArray
    {
        /** @var Cursor $result */
        $result = $this->charges->aggregate([
            ['$match' => [
                '_id' => [
                    '$in' => $claim->getCharges(),
                ],
            ],
            ],
            ['$sort' => [
                'serviceDate' => 1,
                '_id' => 1,
            ]],
        ]);

        return FilterableArray::fromCursor($result);
    }

    public function save(Charge $charge): void
    {
        $this->charges->updateOne(
            ['_id' => $charge->getChargeLine()],
            ['$set' => $charge]
        );
    }

    private static function getClaimsLookup(): array
    {
        return [
            '$lookup' => [
                'from' => 'claims',
                'localField' => '_id',
                'foreignField' => 'charges',
                'as' => 'claims',
            ],
        ];
    }

    /**
     * @param numeric-string[] $charges
     */
    private static function getClaimQuery(
        string $client,
        string $payer,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
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
                'claims' => ['$size' => 0],
            ];
        }

        return $query;
    }
}
