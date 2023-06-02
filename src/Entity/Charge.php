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
        /** @var numeric-string */
        private string $billedAmount,
        /** @var numeric-string|null */
        private ?string $contractAmount,
        private int $billedUnits,
        private PaymentInfo $primaryPaymentInfo,
        private string $payerBalance,
        private ClaimStatus $status = ClaimStatus::pending,
        private ?string $fileId = null,
        private ?string $claimId = null,
        private ?string $qbInvoiceNumber = null,
        private ?string $qbCreditMemoNumber = null,
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

    public function getQbInvoiceNumber(): ?string
    {
        return $this->qbInvoiceNumber;
    }

    public function getQbCreditMemoNumber(): ?string
    {
        return $this->qbCreditMemoNumber;
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
            'payerBalance' => $this->payerBalance ? new Decimal128($this->payerBalance) : new Decimal128('0.00'),
            'status' => $this->status,
            'fileId' => $this->fileId,
            'claimId' => $this->claimId,
            'qbInvoiceNumber' => $this->qbInvoiceNumber,
            'qbCreditMemoNumber' => $this->qbCreditMemoNumber,
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
        Assert::string($data['status']);
        Assert::nullOrString($data['fileId']);
        Assert::nullOrString($data['claimId']);
        Assert::nullOrString($data['qbInvoiceNumber']);
        Assert::nullOrString($data['qbCreditMemoNumber']);

        $this->chargeLine = $data['_id'];
        $this->serviceDate = $data['serviceDate']->toDateTime();
        $this->clientName = $data['clientName'];
        $this->service = $data['service'];

        $billedAmount = (string) $data['billedAmount'];
        Assert::numeric($billedAmount);
        $this->billedAmount = $billedAmount;

        if ($data['contractAmount'] !== null) {
            $contractAmount = (string) $data['contractAmount'];
            Assert::numeric($contractAmount);
            $this->contractAmount = $contractAmount;
        }

        $this->billedUnits = $data['billedUnits'];
        $this->primaryPaymentInfo = $data['primaryPaymentInfo'];
        $this->payerBalance = (string) $data['payerBalance'];

        $this->status = ClaimStatus::from($data['status']);
        $this->fileId = $data['fileId'];
        $this->claimId = $data['claimId'];
        $this->qbInvoiceNumber = $data['qbInvoiceNumber'];
        $this->qbCreditMemoNumber = $data['qbCreditMemoNumber'];
    }
}
