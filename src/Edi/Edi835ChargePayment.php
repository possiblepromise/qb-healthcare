<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

final class Edi835ChargePayment
{
    public string $billingCode;
    public \DateTimeImmutable $serviceDate;
    public string $billed;
    public string $paid;
    public int $units;
    public string $contractualAdjustment = '0.00';
    public string $coinsurance = '0.00';
}
