<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Data\IPPInvoice;

final class ProviderAdjustment implements Persistable
{
    private ?string $qbEntityId;

    public function __construct(
        private ProviderAdjustmentType $type,
        private string $amount
    ) {
    }

    public function getQbEntityId(): ?string
    {
        return $this->qbEntityId;
    }

    public function setQbEntity(IPPInvoice|IPPCreditMemo $entity): void
    {
        $this->qbEntityId = (string) $entity->Id;
    }

    public function getType(): ProviderAdjustmentType
    {
        return $this->type;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function bsonSerialize(): array
    {
        if ($this->qbEntityId === null) {
            throw new \LogicException('The QB entity is required.');
        }

        return [
            'qbEntityId' => $this->qbEntityId,
            'type' => $this->type,
            'amount' => new Decimal128($this->amount),
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        $this->qbEntityId = $data['qbEntityId'];
        $this->type = ProviderAdjustmentType::from($data['type']);
        $this->amount = (string) $data['amount'];
    }
}
