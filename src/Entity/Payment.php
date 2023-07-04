<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Persistable;

final class Payment implements Persistable
{
    use BelongsToCompanyTrait;

    private \DateTime $paymentDate;
    private string $payment;
    private Payer $payer;

    public function __construct(
        private string $paymentRef,
        private string $qbPaymentId,
        private array $claims = []
    ) {
    }

    public function getPaymentRef(): string
    {
        return $this->paymentRef;
    }

    public function getPaymentDate(): \DateTime
    {
        return $this->paymentDate;
    }

    public function getPayment(): string
    {
        return $this->payment;
    }

    public function getPostedDate(): ?\DateTime
    {
        return $this->postedDate;
    }

    public function getPayer(): Payer
    {
        return $this->payer;
    }

    public function getQbPaymentId(): string
    {
        return $this->qbPaymentId;
    }

    public function getClaims(): array
    {
        return $this->claims;
    }

    public function addClaim(string $claim): void
    {
        $this->claims[] = $claim;
    }

    public function bsonSerialize(): array
    {
        if (empty($this->claims)) {
            throw new \RuntimeException('Claims are required before the payment can be saved.');
        }

        // Only some fields are needed to create a new claim
        // The rest are calculated automatically
        return $this->serializeCompanyId([
            '_id' => $this->paymentRef,
            'qbPaymentId' => $this->qbPaymentId,
            'claims' => $this->claims,
        ]);
    }

    public function bsonUnserialize(array $data): void
    {
        $this->paymentRef = $data['_id'];
        $this->paymentDate = $data['paymentDate']?->toDateTime();
        $this->payment = (string) $data['payment'];
        $this->payer = $data['payer'];
        $this->qbPaymentId = $data['qbPaymentId'];
        $this->claims = $data['claims']->getArrayCopy();

        $this->unserializeCompanyId($data);
    }
}
