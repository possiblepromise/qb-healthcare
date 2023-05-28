<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Serializer;

use Doctrine\Common\Annotations\AnnotationReader;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\Service;
use PossiblePromise\QbHealthcare\ValueObject\PayerLine;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Webmozart\Assert\Assert;

final class PayerSerializer
{
    /**
     * @return Payer[]
     */
    public function unserialize(string $file): array
    {
        $lines = $this->processCsv($file);

        $payers = [];

        foreach ($lines as $line) {
            $payer = $payers[$line->payerId] ?? Payer::fromLine($line);
            $payer->addService(
                new Service(
                    billingCode: $line->billingCode,
                    name: $line->serviceName,
                    rate: $line->rate,
                    contractRate: $line->contractRate,
                    unitSize: $line->unitSize
                )
            );

            // In case this was a new payer
            $payers[$line->payerId] = $payer;
        }

        return array_values($payers);
    }

    /**
     * @throws \UnexpectedValueException If the unit size is not formated properly
     */
    private static function formatUnitSize(string $value): int
    {
        if (preg_match('/^(\d+)\s+Minutes/i', $value, $matches)) {
            return (int) $matches[1];
        }

        throw new \UnexpectedValueException(
            sprintf('Unit Size had an unexpected value of %s', $value)
        );
    }

    /**
     * @return PayerLine[]
     */
    private function processCsv(string $file): array
    {
        $classMetadataFactory = new ClassMetadataFactory(
            new AnnotationLoader(new AnnotationReader())
        );
        $metadataAwareNameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        $emptyToNull = static fn (string $value): ?string => empty($value) ? null : $value;

        $context = [
            AbstractNormalizer::CALLBACKS => [
                'address' => $emptyToNull,
                'city' => $emptyToNull,
                'state' => $emptyToNull,
                'zip' => $emptyToNull,
                'phone' => $emptyToNull,
                'email' => $emptyToNull,
                'unitSize' => self::formatUnitSize(...),
            ],
        ];

        $encoders = [new CsvEncoder()];
        $normalizers = [
            new ObjectNormalizer(
                $classMetadataFactory,
                $metadataAwareNameConverter,
                defaultContext: $context
            ),
            new ArrayDenormalizer(),
        ];

        $serializer = new Serializer($normalizers, $encoders);

        $fileData = file_get_contents($file);
        $lines = $serializer->deserialize($fileData, PayerLine::class . '[]', 'csv');
        Assert::isArray($lines);
        Assert::allIsInstanceOf($lines, PayerLine::class);

        return $lines;
    }
}
