<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Serializer\Normalizer;

use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class DateOrNullDenormalizer implements DenormalizerInterface
{
    public const NULLABLE_KEY = 'date_nullable';

    public function __construct(private readonly DateTimeNormalizer $normalizer)
    {
    }

    public function denormalize(
        mixed $data,
        string $type,
        string $format = null,
        array $context = []
    ): ?\DateTime {
        if (isset($context[self::NULLABLE_KEY]) && $context[self::NULLABLE_KEY] === true && empty($data)) {
            return null;
        }

        // Create new \DateTime so we know we can use \DateTime::modify()
        $data = \DateTime::createFromInterface($this->normalizer->denormalize($data, $type, $format, $context));
        $data->modify('00:00:00');

        return $data;
    }

    public function supportsDenormalization(mixed $data, string $type, string $format = null)
    {
        return \is_string($data) && $type === \DateTime::class && $format === 'csv';
    }
}
