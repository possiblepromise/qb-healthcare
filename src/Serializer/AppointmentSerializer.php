<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Serializer;

use PossiblePromise\QbHealthcare\ValueObject\AppointmentLine;
use Symfony\Component\Serializer\Context\Encoder\CsvEncoderContextBuilder;
use Symfony\Component\Serializer\Context\Normalizer\DateTimeNormalizerContextBuilder;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\SerializerInterface;
use Webmozart\Assert\Assert;

final class AppointmentSerializer
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * @return AppointmentLine[]
     */
    public function unserialize(string $file): array
    {
        $fileData = file_get_contents($file);

        $csvEncoderContextBuilder = (new CsvEncoderContextBuilder())
            // Set to something we never use
                // Otherwise it is `.` and messes with headers with a period
            ->withKeySeparator('|')
        ;

        $dateContextBuilder = (new DateTimeNormalizerContextBuilder())
            ->withContext($csvEncoderContextBuilder)
            ->withFormat('m-d-Y')
            ->withTimezone(new \DateTimeZone('UTC'))
        ;

        $objectContextBuilder = (new ObjectNormalizerContextBuilder())
            ->withContext($dateContextBuilder)
            ->withCallbacks([
                'completed' => static fn (string $value): bool => strtolower($value) === 'yes',
                'units' => static fn (string $value): ?int => empty($value) ? null : (int) $value,
                'charge' => static fn (string $value): ?string => $value ?: null,
            ])
        ;

        $lines = $this->serializer->deserialize(
            $fileData,
            AppointmentLine::class . '[]',
            'csv',
            $objectContextBuilder->toArray()
        );
        Assert::isArray($lines);
        Assert::allIsInstanceOf($lines, AppointmentLine::class);

        return $lines;
    }
}
