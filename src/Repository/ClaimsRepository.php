<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
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
        string $claimId,
        string $fileId,
        $invoice,
        $creditMemo,
        array $charges
    ): void {
        Assert::allIsInstanceOf($charges, Charge::class);

        $claim = new Claim(
            id: $claimId,
            fileId: $fileId,
            status: ClaimStatus::processed,
            qbInvoiceId: $invoice->Id,
            qbCreditMemoIds: [$creditMemo->Id],
            charges: (new FilterableArray($charges))->map(static fn (Charge $charge) => $charge->getChargeLine())
        );

        $claim->setQbCompanyId($this->qb->getActiveCompany()->realmId);

        $this->claimData->insertOne($claim);
    }

    public function findOneByCharges(FilterableArray $charges): ?Claim
    {
        return $this->claims->findOne([
            'charges' => [
                '$all' => $charges->map(static fn (Charge $charge): string => $charge->getChargeLine()),
            ],
        ]);
    }

    public function save(Claim $claim): void
    {
        $this->claimData->updateOne(
            ['_id' => $claim->getId()],
            ['$set' => $claim]
        );
    }

    public function getNextUnpaidClaim(): ?Claim
    {
        return $this->claims->findOne(
            [
                'status' => 'processed',
                'billingId' => null,
            ]
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
}
