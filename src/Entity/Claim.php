<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Persistable;

final class Claim implements Persistable
{
    use BelongsToCompanyTrait;

    private string $billedAmount;
    private string $contractAmount;
    private \DateTime $startDate;
    private \DateTime $endDate;
    private PaymentInfo $paymentInfo;

    public function __construct(
        private string $id,
        private string $fileId,
        private ClaimStatus $status = ClaimStatus::processed,
        private ?string $qbInvoiceId = null,
        /** @var numeric-string[] */
        private array $qbCreditMemoIds = [],
        /** @var Charge[] */
        private array $charges = []
    ) {
    }

    public function getId(): string
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

        // Only some fields are needed to create a new claim
        // The rest are calculated automatically
        return $this->serializeCompanyId([
            '_id' => $this->id,
            'fileId' => $this->fileId,
            'status' => $this->status,
            'qbInvoiceId' => $this->qbInvoiceId,
            'qbCreditMemoIds' => $this->qbCreditMemoIds,
            'charges' => $this->charges,
        ]);
    }

    public function bsonUnserialize(array $data): void
    {
        $this->id = $data['_id'];
        $this->fileId = $data['fileId'];
        $this->status = ClaimStatus::from($data['status']);
        $this->qbInvoiceId = $data['qbInvoiceId'];
        $this->qbCreditMemoIds = $data['qbCreditMemoIds']->getArrayCopy();
        $this->billedAmount = (string) $data['billedAmount'];
        $this->contractAmount = (string) $data['contractAmount'];
        $this->startDate = $data['startDate']->toDateTime();
        $this->endDate = $data['endDate']->toDateTime();
        $this->paymentInfo = $data['paymentInfo'];
        $this->charges = $data['charges']->getArrayCopy();

        $this->unserializeCompanyId($data);
    }
}
