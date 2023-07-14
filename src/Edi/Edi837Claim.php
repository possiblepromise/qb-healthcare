<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

final class Edi837Claim
{
    public string $payerId;
    public \DateTimeImmutable $billedDate;
    public string $clientLastName;
    public string $clientFirstName;
    public string $billed;
    /**
     * @var Edi837Charge[]
     */
    public array $charges = [];
}
