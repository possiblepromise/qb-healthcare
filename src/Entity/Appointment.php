<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

final class Appointment implements Persistable
{
    use BelongsToCompanyTrait;

    public function __construct(
        private string $id,
        private Payer $payer,
        private \DateTime $serviceDate,
        private string $clientName,
        private int $units,
        private string $charge,
        private ?\DateTime $dateBilled,
        private bool $completed,
        private ?string $chargeId = null,
        private ?string $qbJournalEntryId = null,
        private ?string $qbReversingJournalEntryId = null
    ) {
    }

    /**
     * @return numeric-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getPayer(): Payer
    {
        return $this->payer;
    }

    public function getServiceDate(): \DateTime
    {
        return $this->serviceDate;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getUnits(): int
    {
        return $this->units;
    }

    public function getCharge(): string
    {
        return $this->charge;
    }

    public function getDateBilled(): ?\DateTime
    {
        return $this->dateBilled;
    }

    public function getChargeId(): ?string
    {
        return $this->chargeId;
    }

    public function setChargeId(string $chargeId): void
    {
        $this->chargeId = $chargeId;
    }

    public function getQbJournalEntryId(): ?string
    {
        return $this->qbJournalEntryId;
    }

    public function getQbReversingJournalEntryId(): ?string
    {
        return $this->qbReversingJournalEntryId;
    }

    public function bsonSerialize(): array
    {
        $data = $this->serializeCompanyId([
            '_id' => $this->id,
            'payer' => $this->payer,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'units' => $this->units,
            'charge' => new Decimal128($this->charge),
            'dateBilled' => $this->dateBilled ? new UTCDateTime($this->dateBilled) : null,
            'completed' => $this->completed,
        ]);

        if ($this->chargeId !== null) {
            $data['chargeId'] = $this->chargeId;
        }

        if ($this->qbJournalEntryId !== null) {
            $data['qbJournalEntryId'] = $this->qbJournalEntryId;
        }

        if ($this->qbReversingJournalEntryId !== null) {
            $data['qbReversingJournalEntryId'] = $this->qbReversingJournalEntryId;
        }

        return $data;
    }

    public function bsonUnserialize(array $data): void
    {
        $this->id = $data['_id'];
        $this->payer = $data['payer'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->units = $data['units'];
        $this->charge = ((string) $data['charge']);
        $this->dateBilled = $data['dateBilled'] ? $data['dateBilled']->toDateTime() : null;
        $this->completed = $data['completed'];
        $this->chargeId = $data['chargeId'] ?? null;
        $this->qbJournalEntryId = $data['qbJournalEntryId'] ?? null;
        $this->qbReversingJournalEntryId = $data['qbReversingJournalEntryId'] ?? null;

        $this->unserializeCompanyId($data);
    }
}
