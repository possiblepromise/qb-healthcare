<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

abstract class MongoRepository
{
    protected static function getArrayFromResult(\Traversable $result): array
    {
        $items = [];

        foreach ($result as $item) {
            $items[] = $item;
        }

        return $items;
    }
}
