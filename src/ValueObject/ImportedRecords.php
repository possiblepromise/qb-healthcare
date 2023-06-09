<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class ImportedRecords
{
    public function __construct(
        public int $new = 0,
        public int $modified = 0
    ) {
    }
}
