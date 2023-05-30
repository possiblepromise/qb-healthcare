<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use PossiblePromise\QbHealthcare\Serializer\Normalizer\DateOrNullDenormalizer;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\Component\Serializer\Annotation\SerializedName;

final class ChargeLine
{
    public function __construct(
        #[SerializedName('Charge Line')]
        public string $chargeLine,
        #[SerializedName('Date of Service')]
        public \DateTime $dateOfService,
        #[SerializedName('Billing Code')]
        public string $billingCode,
        #[SerializedName('Billed Amount')]
        public string $billedAmount,
        #[SerializedName('Contract Amount')]
        public ?string $contractAmount,
        #[SerializedName('Billed Units')]
        public int $billedUnits,
        #[SerializedName('Primary Payer')]
        public string $primaryPayer,
        #[SerializedName('Primary Billed Date')]
        #[Context([DateOrNullDenormalizer::NULLABLE_KEY => true])]
        public ?\DateTime $primaryBilledDate,
        #[SerializedName('Primary Payment Date')]
        #[Context([DateOrNullDenormalizer::NULLABLE_KEY => true])]
        public ?\DateTime $primaryPaymentDate,
        #[SerializedName('Primary Payment')]
        public ?string $primaryPayment,
        #[SerializedName('Primary Payment Ref')]
        public ?string $primaryPaymentRef,
        #[SerializedName('Copay')]
        public ?string $copay,
        #[SerializedName('Coinsurance')]
        public ?string $coinsurance,
        #[SerializedName('Deductible')]
        public ?string $deductible,
        #[SerializedName('Primary Posted Date')]
        #[Context([DateOrNullDenormalizer::NULLABLE_KEY => true])]
        public ?\DateTime $primaryPostedDate,
        #[SerializedName('Payer Balance')]
        public string $payerBalance
    ) {
    }
}
