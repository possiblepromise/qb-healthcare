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
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\ValueObject\ChargeLine;
use PossiblePromise\QbHealthcare\ValueObject\ClaimSummary;
use PossiblePromise\QbHealthcare\ValueObject\ImportedRecords;

final class ChargesRepository extends MongoRepository
{
    use QbApiTrait;

    private Collection $charges;

    public function __construct(MongoClient $client, QuickBooks $qb, private PayersRepository $payers)
    {
        $this->charges = $client->getDatabase()->charges;
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

            $charge->setQbCompanyId($this->qb->getActiveCompany(true)->realmId);

            $result = $this->charges->updateOne(
                ['_id' => $charge->getChargeLine()],
                ['$set' => $charge],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
            $imported->modified += $result->getModifiedCount() ?? 0;
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
        $result = $this->charges->aggregate([
            self::getClaimsLookup(),
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            [
                '$group' => [
                    '_id' => '$primaryPaymentInfo.payer.name',
                    'billedAmount' => ['$sum' => '$billedAmount'],
                    'contractAmount' => ['$sum' => '$contractAmount'],
                    'billedDate' => ['$first' => '$primaryPaymentInfo.billedDate'],
                    'paymentDate' => ['$first' => '$primaryPaymentInfo.paymentDate'],
                    'payment' => ['$sum' => '$primaryPaymentInfo.payment'],
                    'paymentRef' => ['$first' => '$primaryPaymentInfo.paymentRef'],
                    'copay' => ['$sum' => '$primaryPaymentInfo.copay'],
                    'coinsurance' => ['$sum' => '$primaryPaymentInfo.coinsurance'],
                    'deductible' => ['$sum' => '$primaryPaymentInfo.deductible'],
                    'postedDate' => ['$first' => '$primaryPaymentInfo.postedDate'],
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
            billedDate: $data['billedDate']->toDateTime(),
            paymentDate: $data['paymentDate'] ? $data['paymentDate']->toDateTime() : null,
            payment: $data['payment'] ? (string) $data['payment'] : null,
            paymentRef: $data['paymentRef'],
            copay: (string) $data['copay'],
            coinsurance: (string) $data['coinsurance'],
            deductible: (string) $data['deductible'],
            postedDate: $data['postedDate'] ? $data['postedDate']->toDateTime() : null
        );
    }

    /**
     * @param numeric-string[] $charges
     *
     * @return Charge[]
     */
    public function getClaimCharges(string $client, string $payer, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, array $charges = []): array
    {
        $result = $this->charges->aggregate([
            self::getClaimsLookup(),
            ['$match' => self::getClaimQuery($client, $payer, $startDate, $endDate, $charges)],
            ['$sort' => ['serviceDate' => 1, '_id' => 1]],
        ]);

        return self::getArrayFromResult($result);
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

        return self::getArrayFromResult($result);
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
     * @return Charge[]
     */
    public function findWithoutAppointments(): array
    {
        $result = $this->charges->aggregate([
            ['$lookup' => [
                'from' => 'appointments',
                'localField' => '_id',
                'foreignField' => 'chargeId',
                'as' => 'charges',
            ]],
            ['$match' => [
                'charges' => ['$size' => 0],
            ]],
        ]);

        return self::getArrayFromResult($result);
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
                'claims' => ['$size' => 0],
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
