<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

trait BelongsToCompanyTrait
{
    private ?string $qbCompanyId = null;

    public function getQbCompanyId(): ?string
    {
        return $this->qbCompanyId;
    }

    public function setQbCompanyId(string $qbCompanyId): void
    {
        $this->qbCompanyId = $qbCompanyId;
    }

    private function serializeCompanyId(array $data): array
    {
        if ($this->qbCompanyId === null) {
            throw new \LogicException('Company ID is required.');
        }

        $data['qbCompanyId'] = $this->qbCompanyId;

        return $data;
    }

    private function unserializeCompanyId(array $data): void
    {
        $this->qbCompanyId = $data['qbCompanyId'];
    }
}
