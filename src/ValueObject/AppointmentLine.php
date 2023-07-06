<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use PossiblePromise\QbHealthcare\Serializer\Normalizer\DateOrNullDenormalizer;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\Component\Serializer\Annotation\SerializedName;

final class AppointmentLine
{
    public function __construct(
        #[SerializedName('Appointment ID')]
        public readonly string $id,
        #[SerializedName('Payer Name')]
        public readonly string $payerName,
        #[SerializedName('Appt. Date')]
        public readonly \DateTime $serviceDate,
        #[SerializedName('Client Name')]
        public readonly string $clientName,
        #[SerializedName('Completed')]
        public bool $completed,
        #[SerializedName('Billing Code')]
        public readonly string $billingCode,
        #[SerializedName('Units')]
        public readonly ?int $units,
        #[SerializedName('Charge')]
        public readonly ?string $charge,
        #[SerializedName('Date Billed')]
        #[Context([DateOrNullDenormalizer::NULLABLE_KEY => true])]
        public readonly ?\DateTime $dateBilled,
        #[SerializedName('Appointment Status')]
        public readonly string $status,
    ) {
    }
}
