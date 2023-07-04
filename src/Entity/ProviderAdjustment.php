<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use QuickBooksOnline\API\Data\IPPInvoice;

final class ProviderAdjustment implements Persistable
{
    private string $qbEntityId;
    private ProviderAdjustmentType $type;
    private string $amount;

    public function __construct(
        IPPInvoice $qbEntity,
        ProviderAdjustmentType $type,
    ) {
        $this->qbEntityId = (string) $qbEntity->Id;
        $this->type = $type;
        $this->amount = (string) $qbEntity->TotalAmt;
    }

    public function getQbEntityId(): string
    {
        return $this->qbEntityId;
    }

    public function getType(): ProviderAdjustmentType
    {
        return $this->type;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function bsonSerialize(): array
    {
        return [
            'qbEntityId' => $this->qbEntityId,
            'type' => $this->type,
            'amount' => new Decimal128($this->amount),
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        $this->qbEntityId = $data['qbEntityId'];
        $this->type = $data['type'];
        $this->amount = (string) $data['amount'];
    }
}
