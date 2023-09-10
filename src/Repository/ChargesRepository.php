<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Edi\Edi837Claim;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\Entity\Service;
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
    private Collection $unprocessedCharges;

    public function __construct(MongoClient $client, QuickBooks $qb, private PayersRepository $payers)
    {
        $this->charges = $client->getDatabase()->selectCollection('charges');
        $this->unpaidCharges = $client->getDatabase()->selectCollection('unpaidCharges');
        $this->unprocessedCharges = $client->getDatabase()->selectCollection('unprocessedCharges');
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

            $charge->setQbCompanyId($this->getActiveCompanyId());

            $result = $this->charges->updateOne(
                ['_id' => $charge->getChargeLine()],
                ['$setOnInsert' => $charge],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
        }

        return $imported;
    }

    public function getSummary(Edi837Claim $claim): ?ClaimSummary
    {
        $charges = $this->getClaimCharges($claim);
        if (empty($charges)) {
            return null;
        }

        $ids = array_map(
            static fn (Charge $charge): string => $charge->getChargeLine(),
            $charges
        );

        /** @var Cursor $result */
        $result = $this->charges->aggregate([
            ['$match' => [
                '_id' => ['$in' => $ids],
            ]],
            ['$group' => [
                '_id' => '$primaryPaymentInfo.payer.name',
                'clientName' => ['$first' => '$clientName'],
                'billedAmount' => ['$sum' => '$billedAmount'],
                'contractAmount' => ['$sum' => '$contractAmount'],
                'billedDate' => ['$first' => '$primaryPaymentInfo.billedDate'],
                'startDate' => ['$min' => '$serviceDate'],
                'endDate' => ['$max' => '$serviceDate'],
            ],
            ],
        ]);

        $result->next();

        if (!$result->valid()) {
            return null;
        }

        $data = $result->current();

        return new ClaimSummary(
            billingId: sprintf('IN%08s', $ids[0]),
            payer: $data['_id'],
            client: $data['clientName'],
            billedAmount: (string) $data['billedAmount'],
            contractAmount: (string) $data['contractAmount'],
            billedDate: $data['billedDate']->toDateTime(),
            startDate: $data['startDate']->toDateTime(),
            endDate: $data['endDate']->toDateTime(),
            charges: $charges
        );
    }

    /**
     * @return Charge[]
     */
    public function getClaimCharges(Edi837Claim $claim): array
    {
        $charges = [];
        $ids = [];

        foreach ($claim->charges as $charge) {
            /** @var Cursor $result */
            $result = $this->charges->aggregate([
                self::getClaimsLookup(),
                self::getAppointmentsLookup(),
                ['$match' => [
                    'claims' => ['$size' => 0],
                    'appointments' => [
                        '$not' => ['$size' => 0],
                    ],
                    'billedAmount' => new Decimal128($charge->billed),
                    'billedUnits' => $charge->units,
                    'serviceDate' => new UTCDateTime($charge->serviceDate),
                    'clientName' => [
                        '$regex' => new Regex("{$claim->clientLastName},? {$claim->clientFirstName}", 'i'),
                    ],
                    'primaryPaymentInfo.payer._id' => $claim->payerId,
                    'primaryPaymentInfo.billedDate' => new UTCDateTime($claim->billedDate),
                    'service._id' => $charge->billingCode,
                    'qbCompanyId' => $this->getActiveCompanyId(),
                ]],
            ]);

            $selectedCharges = self::getArrayFromResult($result);

            if (empty($selectedCharges)) {
                continue;
            }

            if (\count($selectedCharges) > 1) {
                throw new \RuntimeException('More than one charge matches the provided criteria.');
            }

            /** @var Charge $charge */
            $charge = array_shift($selectedCharges);

            if (\in_array($charge, $ids, true)) {
                throw new \RuntimeException('Charge ' . $charge->getChargeLine() . ' has already been selected.');
            }

            $charges[] = $charge;
        }

        if (empty($charges)) {
            return [];
        }

        if (\count($charges) !== \count($claim->charges)) {
            throw new \RuntimeException('Not all charges could be matched.');
        }

        return $charges;
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

    /**
     * @return Charge[]
     */
    public function findUnpaidFromPayerAndService(Payer $payer, Service $service): array
    {
        $query = [
            'primaryPaymentInfo.payer._id' => $payer->getId(),
            'service._id' => $service->getBillingCode(),
        ];

        /** @var Cursor $unpaidResults */
        $unpaidResults = $this->unpaidCharges->find($query);

        $charges = self::getArrayFromResult($unpaidResults);

        /** @var Cursor $result */
        $unprocessedResults = $this->unprocessedCharges->find($query);

        return array_merge($charges, self::getArrayFromResult($unprocessedResults));
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

    private static function getAppointmentsLookup(): array
    {
        return [
            '$lookup' => [
                'from' => 'completedAppointments',
                'localField' => '_id',
                'foreignField' => 'chargeId',
                'as' => 'appointments',
            ],
        ];
    }
}
