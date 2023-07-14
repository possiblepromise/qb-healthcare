<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

final class Edi837Charge
{
    public string $billingCode;
    public \DateTimeImmutable $serviceDate;
    public string $billed;
    public int $units;
}
