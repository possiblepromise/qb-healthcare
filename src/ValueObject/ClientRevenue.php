<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class ClientRevenue
{
    public function __construct(
        public readonly string $client,
        public readonly string $revenue
    ) {
    }
}
