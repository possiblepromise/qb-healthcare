<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use PossiblePromise\QbHealthcare\Entity\Charge;

final class ClaimSummary
{
    public function __construct(
        private readonly string $payer,
        private readonly string $client,
        private readonly string $billedAmount,
        private readonly string $contractAmount,
        private readonly \DateTimeInterface $billedDate,
        private readonly \DateTimeInterface $startDate,
        private readonly \DateTimeInterface $endDate,
        /** @var Charge[] */
        private readonly array $charges
    ) {
    }

    public function getPayer(): string
    {
        return $this->payer;
    }

    public function getClient(): string
    {
        return $this->client;
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

    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeInterface
    {
        return $this->endDate;
    }

    /**
     * @return Charge[]
     */
    public function getCharges(): array
    {
        return $this->charges;
    }
}
