<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use Symfony\Component\Serializer\Annotation\SerializedName;

final class ChargeLine
{
    public function __construct(
        #[SerializedName('Charge Line')]
        public string $chargeLine,
        #[SerializedName('Date of Service')]
        public \DateTime $dateOfService,
        #[SerializedName('Client Name')]
        public string $clientName,
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
        public \DateTime $primaryBilledDate
    ) {
    }
}
