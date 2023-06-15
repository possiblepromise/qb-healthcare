<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

final class Charge implements Persistable
{
    use BelongsToCompanyTrait;

    public function __construct(
        /** @var numeric-string */
        private string $chargeLine,
        private \DateTime $serviceDate,
        private string $clientName,
        private Service $service,
        /** @var numeric-string */
        private string $billedAmount,
        /** @var numeric-string|null */
        private ?string $contractAmount,
        private int $billedUnits,
        private PaymentInfo $primaryPaymentInfo,
        private string $payerBalance
    ) {
    }

    /**
     * @return numeric-string
     */
    public function getChargeLine(): string
    {
        return $this->chargeLine;
    }

    public function getServiceDate(): \DateTime
    {
        return $this->serviceDate;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getService(): Service
    {
        return $this->service;
    }

    /**
     * @return numeric-string
     */
    public function getBilledAmount(): string
    {
        return $this->billedAmount;
    }

    /**
     * @return numeric-string|null
     */
    public function getContractAmount(): ?string
    {
        return $this->contractAmount;
    }

    public function getBilledUnits(): int
    {
        return $this->billedUnits;
    }

    public function getPrimaryPaymentInfo(): PaymentInfo
    {
        return $this->primaryPaymentInfo;
    }

    public function bsonSerialize(): array
    {
        return $this->serializeCompanyId([
            '_id' => $this->chargeLine,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'service' => $this->service,
            'billedAmount' => new Decimal128($this->billedAmount),
            'contractAmount' => $this->contractAmount ? new Decimal128($this->contractAmount) : null,
            'billedUnits' => $this->billedUnits,
            'primaryPaymentInfo' => $this->primaryPaymentInfo,
            'payerBalance' => $this->payerBalance ? new Decimal128($this->payerBalance) : new Decimal128('0.00'),
        ]);
    }

    public function bsonUnserialize(array $data): void
    {
        $this->chargeLine = $data['_id'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->service = $data['service'];
        $this->billedAmount = ((string) $data['billedAmount']);

        if ($data['contractAmount'] !== null) {
            $this->contractAmount = ((string) $data['contractAmount']);
        }

        $this->billedUnits = $data['billedUnits'];
        $this->primaryPaymentInfo = $data['primaryPaymentInfo'];
        $this->payerBalance = (string) $data['payerBalance'];

        $this->unserializeCompanyId($data);
    }
}
