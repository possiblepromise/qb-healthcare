<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class ClaimSummary
{
    public function __construct(
        private readonly string $payer,
        private readonly string $billedAmount,
        private readonly string $contractAmount,
        private readonly \DateTime $billedDate,
        private readonly ?\DateTime $paymentDate,
        private readonly ?string $payment,
        private readonly ?string $paymentRef,
        private readonly string $copay,
        private readonly string $coinsurance,
        private readonly string $deductible,
        private readonly ?\DateTime $postedDate
    ) {
    }

    public function getPayer(): string
    {
        return $this->payer;
    }

    public function getBilledAmount(): string
    {
        return $this->billedAmount;
    }

    public function getContractAmount(): string
    {
        return $this->contractAmount;
    }

    public function getContractualAdjustment(): string
    {
        return bcsub($this->billedAmount, $this->contractAmount, 2);
    }

    public function getBilledDate(): \DateTime
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

    public function getCopay(): string
    {
        return $this->copay;
    }

    public function getCoinsurance(): string
    {
        return $this->coinsurance;
    }

    public function getDeductible(): string
    {
        return $this->deductible;
    }

    public function getTotalDiscount(): string
    {
        return bcadd($this->getContractualAdjustment(), $this->coinsurance, 2);
    }

    public function getTotal(): string
    {
        return bcsub($this->contractAmount, $this->coinsurance, 2);
    }

    public function getPostedDate(): ?\DateTime
    {
        return $this->postedDate;
    }
}
