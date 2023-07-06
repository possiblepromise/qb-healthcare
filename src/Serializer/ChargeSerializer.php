<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Serializer;

use PossiblePromise\QbHealthcare\ValueObject\ChargeLine;
use Symfony\Component\Serializer\Context\Normalizer\DateTimeNormalizerContextBuilder;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\SerializerInterface;
use Webmozart\Assert\Assert;

final class ChargeSerializer
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * @return ChargeLine[]
     */
    public function unserialize(string $file): array
    {
        $fileData = file_get_contents($file);

        $dateContextBuilder = (new DateTimeNormalizerContextBuilder())
            ->withFormat('m-d-Y')
            ->withTimezone(new \DateTimeZone('UTC'))
        ;

        $objectContextBuilder = (new ObjectNormalizerContextBuilder())
            ->withContext($dateContextBuilder)
            ->withCallbacks([
                'contractAmount' => static fn (string $value): ?string => empty($value) ? null : $value,
            ])
        ;

        $lines = $this->serializer->deserialize(
            $fileData,
            ChargeLine::class . '[]',
            'csv',
            $objectContextBuilder->toArray()
        );
        Assert::isArray($lines);
        Assert::allIsInstanceOf($lines, ChargeLine::class);

        return $lines;
    }
}
