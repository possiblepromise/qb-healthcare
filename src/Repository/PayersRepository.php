<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use MongoDB\Database;
use PossiblePromise\QbHealthcare\Entity\Payer;

final class PayersRepository
{
    private Collection $payers;

    public function __construct(Database $db)
    {
        $this->payers = $db->payers;
    }

    /**
     * @param Payer[] $data
     */
    public function import(array $data): void
    {
        foreach ($data as $payer) {
            $this->payers->updateOne(
                ['_id' => $payer->getId()],
                ['$set' => $payer],
                ['upsert' => true],
            );
        }
    }
}
