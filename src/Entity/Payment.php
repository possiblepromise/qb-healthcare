<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

final class Payment implements Persistable
{
    use BelongsToCompanyTrait;

    public function __construct(
        private string $paymentRef,
        private \DateTime $paymentDate,
        private string $payment,
        private ?\DateTime $postedDate,
        private Payer $payer,
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

        return $this->serializeCompanyId([
            '_id' => $this->paymentRef,
            'paymentDate' => $this->paymentDate ? new UTCDateTime($this->paymentDate) : null,
            'payment' => new Decimal128($this->payment),
            'postedDate' => $this->postedDate ? new UTCDateTime($this->postedDate) : null,
            'payer' => $this->payer,
            'qbPaymentId' => $this->qbPaymentId,
            'claims' => $this->claims,
        ]);
    }

    public function bsonUnserialize(array $data): void
    {
        $this->paymentRef = $data['_id'];
        $this->paymentDate = $data['paymentDate'] ? $data['paymentDate']->toDateTime() : null;
        $this->payment = (string) $data['payment'];
        $this->postedDate = $data['postedDate'] ? $data['postedDate']->toDateTime() : null;
        $this->payer = $data['payer'];
        $this->qbPaymentId = $data['qbPaymentId'];
        $this->claims = $data['claims']->getArrayCopy();

        $this->unserializeCompanyId($data);
    }
}
