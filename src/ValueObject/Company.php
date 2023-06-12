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
        public bool $active = true
    ) {
    }

    public function getAccruedRevenueAccount(): ?string
    {
        return $this->accruedRevenueAccount;
    }

    public function setAccruedRevenueAccount(string $accruedRevenueAccount): void
    {
        $this->accruedRevenueAccount = $accruedRevenueAccount;
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

        return $data;
    }

    public function bsonUnserialize(array $data): void
    {
        $this->realmId = $data['_id'];
        $this->companyName = $data['companyName'];
        $this->accessToken = $data['accessToken'];
        $this->refreshToken = $data['refreshToken'];

        $this->accruedRevenueAccount = $data['accruedRevenueAccount'] ?? null;

        if (isset($data['active'])) {
            $this->active = $data['active'];
        }
    }
}
