<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use Webmozart\Assert\Assert;

final class Service implements Persistable
{
    public function __construct(
        private string $billingCode,
        private string $name,
        private string $rate,
        private string $contractRate,
        private int $unitSize
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

    public function bsonSerialize(): array
    {
        return [
            '_id' => $this->billingCode,
            'name' => $this->name,
            'rate' => new Decimal128($this->rate),
            'contractRate' => new Decimal128($this->contractRate),
            'unitSize' => $this->unitSize,
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        Assert::string($data['_id']);
        Assert::string($data['name']);
        Assert::isInstanceOf($data['rate'], Decimal128::class);
        Assert::isInstanceOf($data['contractRate'], Decimal128::class);
        Assert::integer($data['unitSize']);

        $this->billingCode = $data['_id'];
        $this->name = $data['name'];
        $this->rate = (string) $data['rate'];
        $this->contractRate = (string) $data['contractRate'];
        $this->unitSize = $data['unitSize'];
    }
}
