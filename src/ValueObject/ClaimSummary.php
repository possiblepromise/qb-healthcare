<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class ClaimSummary
{
    public function __construct(
        private readonly string $payer,
        private readonly string $billedAmount,
        private readonly string $contractAmount,
        private readonly \DateTimeInterface $billedDate
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

    public function getBilledDate(): \DateTimeInterface
    {
        return $this->billedDate;
    }
}
