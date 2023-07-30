<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use MongoDB\Model\BSONIterator;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\QuickBooks;

final class PayersRepository extends MongoRepository
{
    private Collection $payers;

    public function __construct(MongoClient $client, private QuickBooks $qb)
    {
        $this->payers = $client->getDatabase()->payers;
    }

    /**
     * @param Payer[] $data
     */
    public function import(array $data): void
    {
        foreach ($data as $payer) {
            $payer->setQbCompanyId($this->qb->getActiveCompany()->realmId);

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

        return $result->current();
    }

    public function findOneByName($payer): ?Payer
    {
        $result = $this->payers->aggregate([
            ['$match' => ['name' => $payer]],
            ['$project' => ['services' => false]],
        ]);

        $result->next();

        return $result->current();
    }

    /**
     * @return string[]
     */
    public function findAllServiceItemIds()
    {
        $result = $this->payers->aggregate([
            ['$unwind' => '$services'],
            ['$group' => [
                '_id' => '$services.qbItemId',
            ]],
        ]);

        $itemIds = [];

        foreach ($result as $item) {
            $itemIds[] = $item['_id'];
        }

        return $itemIds;
    }

    /**
     * @return Payer[]
     */
    public function findAll(): array
    {
        /** @var Cursor $result */
        $result = $this->payers->find();

        return self::getArrayFromResult($result);
    }

    public function save(Payer $payer): void
    {
        $this->payers->updateOne(
            ['_id' => $payer->getId()],
            ['$set' => $payer]
        );
    }
}
