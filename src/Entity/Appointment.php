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
        /** @var numeric-string */
        private string $id,
        private Payer $payer,
        private Service $service,
        private \DateTime $serviceDate,
        private string $clientName,
        private int $units,
        /** @var numeric-string */
        private string $charge,
        private ?\DateTime $dateBilled,
        private ?string $chargeId = null
    ) {
    }

    /**
     * @return numeric-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function setChargeId(string $chargeId): void
    {
        $this->chargeId = $chargeId;
    }

    public function getUnits(): int
    {
        return $this->units;
    }

    public function bsonSerialize(): array
    {
        $data = $this->serializeCompanyId([
            '_id' => $this->id,
            'payer' => $this->payer,
            'service' => $this->service,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'units' => $this->units,
            'charge' => new Decimal128($this->charge),
            'dateBilled' => $this->dateBilled ? new UTCDateTime($this->dateBilled) : null,
        ]);

        if ($this->chargeId !== null) {
            $data['chargeId'] = $this->chargeId;
        }

        return $data;
    }

    public function bsonUnserialize(array $data): void
    {
        $this->id = $data['_id'];
        $this->payer = $data['payer'];
        $this->service = $data['service'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->units = $data['units'];
        $this->charge = ((string) $data['charge']);
        $this->dateBilled = $data['dateBilled'] ? $data['dateBilled']->toDateTime() : null;

        if (isset($data['chargeId'])) {
            $this->chargeId = $data['chargeId'];
        }

        $this->unserializeCompanyId($data);
    }
}
