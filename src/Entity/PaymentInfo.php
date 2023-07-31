<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

final class PaymentInfo implements Persistable
{
    public function __construct(
        private Payer $payer,
        private \DateTimeInterface $billedDate,
        private ?\DateTimeInterface $paymentDate = null,
        private ?string $payment = null,
        private ?string $paymentRef = null,
        private string $copay = '0.00',
        private string $coinsurance = '0.00',
        private string $deductible = '0.00'
    ) {
    }

    public function getPayer(): Payer
    {
        return $this->payer;
    }

    public function getBilledDate(): \DateTimeInterface
    {
        return $this->billedDate;
    }

    public function getPaymentDate(): ?\DateTimeInterface
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(?\DateTimeInterface $paymentDate): self
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getPayment(): ?string
    {
        return $this->payment;
    }

    public function setPayment(?string $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    public function getPaymentRef(): ?string
    {
        return $this->paymentRef;
    }

    public function setPaymentRef(?string $paymentRef): self
    {
        $this->paymentRef = $paymentRef;

        return $this;
    }

    /**
     * @return numeric-string|null
     */
    public function getCoinsurance(): ?string
    {
        return $this->coinsurance;
    }

    public function setCoinsurance(?string $coinsurance): self
    {
        $this->coinsurance = $coinsurance ?? '0.00';

        return $this;
    }

    public function bsonSerialize(): array
    {
        return [
            'payer' => $this->payer,
            'billedDate' => new UTCDateTime($this->billedDate),
            'paymentDate' => $this->paymentDate ? new UTCDateTime($this->paymentDate) : null,
            'payment' => $this->payment ? new Decimal128($this->payment) : null,
            'paymentRef' => $this->paymentRef,
            'copay' => new Decimal128($this->copay),
            'coinsurance' => new Decimal128($this->coinsurance),
            'deductible' => new Decimal128($this->deductible),
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        $this->payer = $data['payer'];
        $this->billedDate = $data['billedDate']->toDateTime();
        $this->paymentDate = $data['paymentDate']?->toDateTime();
        $this->payment = $data['payment'] ? (string) $data['payment'] : null;
        $this->paymentRef = $data['paymentRef'] ?? null;
        $this->copay = ((string) $data['copay']);
        $this->coinsurance = ((string) $data['coinsurance']);
        $this->deductible = ((string) $data['deductible']);
    }
}
