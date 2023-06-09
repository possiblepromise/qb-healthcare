<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

/**
 * @psalm-type Data=array{
 *   _id: numeric-string,
 *   payer: Payer,
 *   service: Service,
 *   serviceDate: UTCDateTime,
 *   clientName: string,
 *    units: int,
 *   charge: Decimal128,
 *    dateBilled: UTCDateTime|null,
 *    chargeId: string|null
 * }
 */
final class Appointment implements Persistable
{
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
        private ?string $chargeId
    ) {
    }

    /**
     * @return numeric-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return Data
     */
    public function bsonSerialize(): array
    {
        return [
            '_id' => $this->id,
            'payer' => $this->payer,
            'service' => $this->service,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'units' => $this->units,
            'charge' => new Decimal128($this->charge),
            'dateBilled' => $this->dateBilled ? new UTCDateTime($this->dateBilled) : null,
            'chargeId' => $this->chargeId,
        ];
    }

    /**
     * @param Data $data
     */
    public function bsonUnserialize(array $data): void
    {
        $this->id = $data['_id'];
        $this->payer = $data['payer'];
        $this->service = $data['service'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->units = $data['units'];
        /** @psalm-suppress PropertyTypeCoercion */
        $this->charge = ((string)$data['charge']);
        $this->dateBilled = $data['dateBilled'] ? $data['dateBilled']->toDateTime() : null;
        $this->chargeId = $data['chargeId'];
    }
}