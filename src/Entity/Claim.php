<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;

final class Claim implements Persistable
{
    use BelongsToCompanyTrait;

    public function __construct(
        private string $id,
        private string $fileId,
        private string $billedAmount,
        private string $contractAmount,
        private PaymentInfo $paymentInfo,
        private ClaimStatus $status = ClaimStatus::processed,
        private ?string $qbInvoiceId = null,
        /** @var numeric-string[] */
        private array $qbCreditMemoIds = [],
        /** @var Charge[] */
        private array $charges = []
    ) {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function getStatus(): ClaimStatus
    {
        return $this->status;
    }

    public function getQbInvoiceId(): string
    {
        return $this->qbInvoiceId;
    }

    public function getQbCreditMemoIds(): array
    {
        return $this->qbCreditMemoIds;
    }

    public function getBilledAmount(): string
    {
        return $this->billedAmount;
    }

    public function getContractAmount(): string
    {
        return $this->contractAmount;
    }

    public function getPaymentInfo(): PaymentInfo
    {
        return $this->paymentInfo;
    }

    /**
     * @return Charge[]
     */
    public function getCharges(): array
    {
        return $this->charges;
    }

    public function addCharge(string $charge): void
    {
        $this->charges[] = $charge;
    }

    public function bsonSerialize(): array
    {
        if (empty($this->charges)) {
            throw new \RuntimeException('Charges are required before the claim can be saved.');
        }

        return $this->serializeCompanyId([
            '_id' => $this->id,
            'fileId' => $this->fileId,
            'status' => $this->status,
            'qbInvoiceId' => $this->qbInvoiceId,
            'qbCreditMemoIds' => $this->qbCreditMemoIds,
            'billedAmount' => new Decimal128($this->billedAmount),
            'contractAmount' => new Decimal128($this->contractAmount),
            'paymentInfo' => $this->paymentInfo,
            'charges' => $this->charges,
        ]);
    }

    public function bsonUnserialize(array $data): void
    {
        $this->id = $data['_id'];
        $this->fileId = $data['fileId'];
        $this->status = ClaimStatus::from($data['status']);
        $this->qbInvoiceId = $data['qbInvoiceId'];
        $this->qbCreditMemoIds = $data['qbCreditMemoIds'];
        $this->billedAmount = (string) $data['billedAmount'];
        $this->contractAmount = (string) $data['contractAmount'];
        $this->paymentInfo = $data['paymentInfo'];
        $this->charges = $data['charges']->getArrayCopy();

        $this->unserializeCompanyId($data);
    }
}
