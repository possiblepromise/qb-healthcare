<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

final class HcfaInfo
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $payerId,
        /** @var numeric-string */
        public readonly string $total,
        public readonly \DateTimeImmutable $billedDate,
        public readonly string $claimId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly \DateTimeImmutable $fromDate,
        public readonly \DateTimeImmutable $toDate
    ) {
    }
}
