<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\ClaimStatus;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use Webmozart\Assert\Assert;

final class ClaimsRepository extends MongoRepository
{
    use QbApiTrait;

    private Collection $claims;
    private Collection $claimData;

    public function __construct(MongoClient $client, QuickBooks $qb, private PayersRepository $payers, private ChargesRepository $charges)
    {
        $this->claims = $client->getDatabase()->selectCollection('claims');
        $this->claimData = $client->getDatabase()->selectCollection('claimData');
        $this->qb = $qb;
    }

    /**
     * @param Charge[] $charges
     */
    public function createClaim(
        string $billingId,
        $invoice,
        $creditMemo,
        array $charges
    ): Claim {
        Assert::allIsInstanceOf($charges, Charge::class);

        $claim = new Claim(
            billingId: $billingId,
            status: ClaimStatus::processed,
            qbInvoiceId: $invoice->Id,
            qbCreditMemoIds: [$creditMemo->Id],
            charges: (new FilterableArray($charges))->map(static fn (Charge $charge) => $charge->getChargeLine())
        );

        $claim->setQbCompanyId($this->getActiveCompanyId());

        $this->claimData->insertOne($claim);

        return $claim;
    }

    /**
     * @param Charge[] $charges
     */
    public function findOneByCharges(array $charges): ?Claim
    {
        return $this->claims->findOne([
            'charges' => [
                '$all' => array_map(static fn (Charge $charge): string => $charge->getChargeLine(), $charges),
            ],
        ]);
    }

    /**
     * @param Charge[] $charges
     *
     * @return Claim[]
     */
    public function findByCharges(array $charges): array
    {
        /** @var Cursor $result */
        $result = $this->claims->find([
            'charges' => [
                '$in' => array_map(static fn (Charge $charge): string => $charge->getChargeLine(), $charges),
            ],
        ]);

        return self::getArrayFromResult($result);
    }

    public function save(Claim $claim): void
    {
        $this->claimData->updateOne(
            ['_id' => $claim->getId()],
            ['$set' => $claim]
        );
    }

    public function get(string $claimId): Claim
    {
        return $this->claims->findOne(['_id' => $claimId]);
    }

    public function markPaid(string $paymentRef): void
    {
        $result = $this->claims->find(['paymentInfo.paymentRef' => $paymentRef]);

        $claimIds = [];

        /** @var Claim $claim */
        foreach ($result as $claim) {
            $claimIds[] = $claim->getId();
        }

        $this->claimData->updateMany(
            ['_id' => ['$in' => $claimIds]],
            ['$set' => ['status' => ClaimStatus::paid]]
        );
    }

    public function findByDateAndBilledAmount(\DateTime $date, $billed)
    {
        $result = $this->claims->find([
            'paymentInfo.billedDate' => new UTCDateTime($date),
            'billedAmount' => new Decimal128($billed),
            'billingId' => null,
        ]);

        return FilterableArray::fromCursor($result);
    }

    public function findOneByBillingId(string $billingId): ?Claim
    {
        return $this->claims->findOne([
            'billingId' => $billingId,
        ]);
    }

    /**
     * @return Claim[]
     */
    public function findUnpaid(?\DateTimeInterface $endDate = null): array
    {
        $matchQuery = [
            'status' => ClaimStatus::processed,
            'qbCompanyId' => $this->getActiveCompanyId(),
        ];

        if ($endDate !== null) {
            $matchQuery['paymentInfo.billedDate'] = [
                '$lte' => new UTCDateTime($endDate),
            ];
            $matchQuery['$or'] = [
                [
                    'paymentInfo.paymentDate' => null,
                ],
                [
                    'paymentInfo.paymentDate' => [
                        '$gt' => new UTCDateTime($endDate),
                    ],
                ],
            ];

            unset($matchQuery['status']);
        }

        /** @var Cursor $result */
        $result = $this->claims->aggregate([
            ['$match' => $matchQuery],
            ['$sort' => [
                'paymentInfo.billedDate' => 1,
                'billingId' => 1,
            ]],
        ]);

        return self::getArrayFromResult($result);
    }
}
