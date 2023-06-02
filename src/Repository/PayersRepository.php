<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use MongoDB\Model\BSONIterator;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Payer;

final class PayersRepository
{
    private Collection $payers;

    public function __construct(MongoClient $client)
    {
        $this->payers = $client->getDatabase()->payers;
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

    public function findOneByNameAndService(string $name, string $billingCode): ?Payer
    {
        /** @var BSONIterator $result */
        $result = $this->payers->aggregate([
            ['$match' => ['name' => $name, 'services._id' => $billingCode]],
            ['$unwind' => '$services'],
            ['$match' => ['services._id' => $billingCode]],
        ]);

        $result->next();

        /** @var Payer|null */
        return $result->current();
    }

    /**
     * @return string[]
     */
    public function getPayers(): array
    {
        $result = $this->payers->find([], ['name' => 1]);

        $payers = [];
        /** @var Payer $payer */
        foreach ($result as $payer) {
            $payers[] = $payer->getName();
        }

        return $payers;
    }
}
