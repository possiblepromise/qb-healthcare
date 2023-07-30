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
        private string $chargeLine,
        private \DateTime $serviceDate,
        private string $clientName,
        private Service $service,
        private string $billedAmount,
        private ?string $contractAmount,
        private int $billedUnits,
        private PaymentInfo $primaryPaymentInfo,
        private string $payerBalance = '0.00'
    ) {
    }

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

    public function getBilledAmount(): string
    {
        return $this->billedAmount;
    }

    public function getContractAmount(): ?string
    {
        return $this->contractAmount;
    }

    public function setContractAmount(string $contractAmount): self
    {
        $this->contractAmount = $contractAmount;

        return $this;
    }

    public function getBilledUnits(): int
    {
        return $this->billedUnits;
    }

    public function getPrimaryPaymentInfo(): PaymentInfo
    {
        return $this->primaryPaymentInfo;
    }

    public function setPayerBalance(string $payerBalance): self
    {
        $this->payerBalance = $payerBalance;

        return $this;
    }

    public function bsonSerialize(): array
    {
        $data = [
            '_id' => $this->chargeLine,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'service' => $this->service,
            'billedAmount' => new Decimal128($this->billedAmount),
            'contractAmount' => $this->contractAmount ? new Decimal128($this->contractAmount) : null,
            'billedUnits' => $this->billedUnits,
            'primaryPaymentInfo' => $this->primaryPaymentInfo,
            'payerBalance' => new Decimal128($this->payerBalance),
        ];

        return $this->serializeCompanyId($data);
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

    public function __clone(): void
    {
        $this->primaryPaymentInfo = clone $this->primaryPaymentInfo;
    }
}
