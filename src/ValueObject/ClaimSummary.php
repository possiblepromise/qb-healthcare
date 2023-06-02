<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class ClaimSummary
{
    public function __construct(
        /** @var numeric-string */
        private readonly string $billedAmount,
        /** @var numeric-string */
        private readonly string $contractAmount,
        /** @var numeric-string */
        private readonly string $coinsurance,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function getBilledAmount(): string
    {
        return $this->billedAmount;
    }

    /**
     * @return numeric-string
     */
    public function getContractAmount(): string
    {
        return $this->contractAmount;
    }

    /**
     * @return numeric-string
     */
    public function getContractualAdjustment(): string
    {
        return bcsub($this->billedAmount, $this->contractAmount, 2);
    }

    /**
     * @return numeric-string
     */
    public function getCoinsurance(): string
    {
        return $this->coinsurance;
    }

    /**
     * @return numeric-string
     */
    public function getTotalDiscount(): string
    {
        return bcadd($this->getContractualAdjustment(), $this->coinsurance, 2);
    }

    /**
     * @return numeric-string
     */
    public function getTotal(): string
    {
        return bcsub($this->contractAmount, $this->coinsurance, 2);
    }
}
