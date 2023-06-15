<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use MongoDB\BSON\Persistable;

final class Company implements Persistable
{
    public function __construct(
        public string $realmId,
        public string $companyName,
        public Token $accessToken,
        public Token $refreshToken,
        public ?string $accruedRevenueAccount = null,
        public ?string $paymentTerm = null,
        public ?string $contractualAdjustmentItem = null,
        public ?string $coinsuranceItem = null,
        public bool $active = true
    ) {
    }

    public function bsonSerialize(): array
    {
        $data = [
            '_id' => $this->realmId,
            'companyName' => $this->companyName,
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'active' => $this->active,
        ];

        if ($this->accruedRevenueAccount) {
            $data['accruedRevenueAccount'] = $this->accruedRevenueAccount;
        }

        if ($this->paymentTerm) {
            $data['paymentTerm'] = $this->paymentTerm;
        }

        if ($this->contractualAdjustmentItem) {
            $data['contractualAdjustmentItem'] = $this->contractualAdjustmentItem;
        }

        if ($this->coinsuranceItem) {
            $data['coinsuranceItem'] = $this->coinsuranceItem;
        }

        return $data;
    }

    public function bsonUnserialize(array $data): void
    {
        $this->realmId = $data['_id'];
        $this->companyName = $data['companyName'];
        $this->accessToken = $data['accessToken'];
        $this->refreshToken = $data['refreshToken'];
        $this->accruedRevenueAccount = $data['accruedRevenueAccount'] ?? null;
        $this->paymentTerm = $data['paymentTerm'] ?? null;
        $this->contractualAdjustmentItem = $data['contractualAdjustmentItem'] ?? null;
        $this->coinsuranceItem = $data['coinsuranceItem'] ?? null;
        $this->active = $data['active'] ?? true;
    }
}
