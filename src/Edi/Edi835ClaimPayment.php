<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

final class Edi835ClaimPayment
{
    public string $amountClaimed;
    public string $amountPaid;
    public string $patientResponsibility;
    public string $clientLastName;
    public string $clientFirstName;
    /**
     * @var Edi835ChargePayment[]
     */
    public array $charges = [];
}
