<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Claim;
use PossiblePromise\QbHealthcare\Entity\ClaimStatus;
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use PossiblePromise\QbHealthcare\ValueObject\ClaimSummary;
use Webmozart\Assert\Assert;

final class ClaimsRepository extends MongoRepository
{
    use QbApiTrait;

    private Collection $claims;

    public function __construct(MongoClient $client, QuickBooks $qb, private PayersRepository $payers, private ChargesRepository $charges)
    {
        $this->claims = $client->getDatabase()->claims;
        $this->qb = $qb;
    }

    /**
     * @param Charge[] $charges
     */
    public function createClaim(
        string $claimId,
        string $fileId,
        ClaimSummary $claimSummary,
        $invoice,
        $creditMemo,
        array $charges
    ): void {
        Assert::allIsInstanceOf($charges, Charge::class);

        $payer = $this->payers->findOneByName($claimSummary->getPayer());

        $paymentInfo = new PaymentInfo(
            payer: $payer,
            billedDate: $claimSummary->getBilledDate(),
            paymentDate: $claimSummary->getPaymentDate(),
            payment: $claimSummary->getPayment(),
            paymentRef: $claimSummary->getPaymentRef(),
            copay: $claimSummary->getCopay(),
            coinsurance: $claimSummary->getCoinsurance(),
            deductible: $claimSummary->getDeductible(),
            postedDate: $claimSummary->getPostedDate()
        );

        $claim = new Claim(
            id: $claimId,
            fileId: $fileId,
            billedAmount: $claimSummary->getBilledAmount(),
            contractAmount: $claimSummary->getContractAmount(),
            paymentInfo: $paymentInfo,
            status: ClaimStatus::processed,
            qbInvoiceId: $invoice->Id,
            qbCreditMemoIds: [$creditMemo->Id],
            charges: (new FilterableArray($charges))->map(static fn (Charge $charge) => $charge->getChargeLine())
        );

        $claim->setQbCompanyId($this->qb->getActiveCompany()->realmId);

        $this->claims->insertOne($claim);
    }
}
