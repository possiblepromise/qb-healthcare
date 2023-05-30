<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use Symfony\Component\Serializer\Annotation\SerializedName;

final class PayerLine
{
    public function __construct(
        #[SerializedName('Payer ID')]
        public readonly string $payerId,
        #[SerializedName('Payer Name')]
        public readonly string $payerName,
        #[SerializedName('Type')]
        public readonly string $type,
        #[SerializedName('Address')]
        public readonly ?string $address,
        #[SerializedName('City')]
        public readonly ?string $city,
        #[SerializedName('State')]
        public readonly ?string $state,
        #[SerializedName('Zip')]
        public readonly ?string $zip,
        #[SerializedName('Email')]
        public readonly ?string $email,
        #[SerializedName('Phone')]
        public readonly ?string $phone,
        #[SerializedName('Billing Code')]
        public readonly string $billingCode,
        #[SerializedName('Service Name')]
        public readonly string $serviceName,
        #[SerializedName('Rate')]
        public readonly string $rate,
        #[SerializedName('Contract Rate')]
        public readonly string $contractRate,
        #[SerializedName('Unit Size')]
        public readonly int $unitSize,
    ) {
    }
}
