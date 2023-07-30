<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;

final class Service implements Persistable
{
    public function __construct(
        private string $billingCode,
        private string $name,
        private string $rate,
        private string $contractRate,
        private int $unitSize,
        private ?string $qbItemId = null
    ) {
    }

    public function getBillingCode(): string
    {
        return $this->billingCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function getContractRate(): string
    {
        return $this->contractRate;
    }

    public function setContractRate(string $contractRate): void
    {
        $this->contractRate = $contractRate;
    }

    public function getQbItemId(): ?string
    {
        return $this->qbItemId;
    }

    public function setQbItemId(string $item): void
    {
        $this->qbItemId = $item;
    }

    public function bsonSerialize(): array
    {
        $data = [
            '_id' => $this->billingCode,
            'name' => $this->name,
            'rate' => new Decimal128($this->rate),
            'contractRate' => new Decimal128($this->contractRate),
            'unitSize' => $this->unitSize,
        ];

        if ($this->qbItemId) {
            $data['qbItemId'] = $this->qbItemId;
        }

        return $data;
    }

    public function bsonUnserialize(array $data): void
    {
        $this->billingCode = $data['_id'];
        $this->name = $data['name'];
        $this->rate = (string) $data['rate'];
        $this->contractRate = (string) $data['contractRate'];
        $this->unitSize = $data['unitSize'];

        if (isset($data['qbItemId'])) {
            $this->qbItemId = $data['qbItemId'];
        }
    }
}
