<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;
use Webmozart\Assert\Assert;

final class Charge implements Persistable
{
    public function __construct(
        private string $chargeLine,
        private \DateTime $serviceDate,
        private string $clientName,
        private Service $service,
        private string $billedAmount,
        private ?string $contractAmount,
        private int $billedUnits,
        private PaymentInfo $primaryPaymentInfo,
        private string $payerBalance
    ) {
    }

    public function getChargeLine(): string
    {
        return $this->chargeLine;
    }

    public function bsonSerialize(): array
    {
        return [
            '_id' => $this->chargeLine,
            'serviceDate' => new UTCDateTime($this->serviceDate),
            'clientName' => $this->clientName,
            'service' => $this->service,
            'billedAmount' => new Decimal128($this->billedAmount),
            'contractAmount' => $this->contractAmount ? new Decimal128($this->contractAmount) : null,
            'billedUnits' => $this->billedUnits,
            'primaryPaymentInfo' => $this->primaryPaymentInfo,
            'payerBalance' => $this->payerBalance ? new Decimal128($this->payerBalance) : null,
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        Assert::string($data['_id']);
        Assert::isInstanceOf($data['serviceDate'], UTCDateTime::class);
        Assert::string($data['clientName']);
        Assert::isInstanceOf($data['service'], Service::class);
        Assert::isInstanceOf($data['billedAmount'], Decimal128::class);
        Assert::nullOrIsInstanceOf($data['contractAmount'], Decimal128::class);
        Assert::integer($data['billedUnits']);
        Assert::isInstanceOf($data['primaryPaymentInfo'], PaymentInfo::class);
        Assert::nullOrIsInstanceOf($data['payerBalance'], Decimal128::class);

        $this->chargeLine = $data['_id'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->service = $data['service'];
        $this->billedAmount = (string) $data['billedAmount'];

        if ($data['contractAmount'] !== null) {
            $this->contractAmount = (string) $data['contractAmount'];
        }

        $this->billedUnits = $data['billedUnits'];
        $this->primaryPaymentInfo = $data['primaryPaymentInfo'];
        $this->payerBalance = (string) $data['payerBalance'];
    }
}
