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
        private ?\DateTime $billedDate = null,
        private ?\DateTime $paymentDate = null,
        private ?string $payment = null,
        private ?string $paymentRef = null,
        /** @var numeric-string|null */
        private ?string $copay = null,
        /** @var numeric-string|null */
        private ?string $coinsurance = null,
        /** @var numeric-string|null */
        private ?string $deductible = null,
        private ?\DateTime $postedDate = null
    ) {
    }

    public function getPayer(): Payer
    {
        return $this->payer;
    }

    public function getBilledDate(): ?\DateTime
    {
        return $this->billedDate;
    }

    public function getPaymentDate(): ?\DateTime
    {
        return $this->paymentDate;
    }

    public function getPayment(): ?string
    {
        return $this->payment;
    }

    public function getPaymentRef(): ?string
    {
        return $this->paymentRef;
    }

    /**
     * @return numeric-string|null
     */
    public function getCoinsurance(): ?string
    {
        return $this->coinsurance;
    }

    public function getPostedDate(): ?\DateTime
    {
        return $this->postedDate;
    }

    public function bsonSerialize(): array
    {
        return [
            'payer' => $this->payer,
            'billedDate' => $this->billedDate ? new UTCDateTime($this->billedDate) : null,
            'paymentDate' => $this->paymentDate ? new UTCDateTime($this->paymentDate) : null,
            'payment' => $this->payment ? new Decimal128($this->payment) : null,
            'paymentRef' => $this->paymentRef,
            'copay' => $this->copay ? new Decimal128($this->copay) : new Decimal128('0.00'),
            'coinsurance' => $this->coinsurance ? new Decimal128($this->coinsurance) : new Decimal128('0.00'),
            'deductible' => $this->deductible ? new Decimal128($this->deductible) : new Decimal128('0.00'),
            'postedDate' => $this->postedDate ? new UTCDateTime($this->postedDate) : null,
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        $this->payer = $data['payer'];
        $this->billedDate = $data['billedDate'] ? $data['billedDate']->toDateTime() : null;
        $this->paymentDate = $data['paymentDate'] ? $data['paymentDate']->toDateTime() : null;
        $this->payment = $data['payment'] ? (string) $data['payment'] : null;
        $this->paymentRef = $data['paymentRef'] ?? null;
        $this->copay = ((string) $data['copay']);
        $this->coinsurance = ((string) $data['coinsurance']);
        $this->deductible = ((string) $data['deductible']);
        $this->postedDate = $data['postedDate'] ? $data['postedDate']->toDateTime() : null;
    }
}
