<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;

final class Edi835Payment
{
    public string $payment;
    public \DateTimeImmutable $paymentDate;
    public string $paymentRef;
    public string $payerName;
    /**
     * @var Edi835ClaimPayment[]
     */
    public array $claims = [];
    /**
     * @var ProviderAdjustment[]
     */
    public array $providerAdjustments = [];
}
