<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

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
        $this->claims = $client->getDatabase()->claims;
        $this->claimData = $client->getDatabase()->claimData;
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
}
