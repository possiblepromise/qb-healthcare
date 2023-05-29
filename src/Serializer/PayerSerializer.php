<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Serializer;

use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\Service;
use PossiblePromise\QbHealthcare\ValueObject\PayerLine;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\SerializerInterface;
use Webmozart\Assert\Assert;

final class PayerSerializer
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

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
        $emptyToNull = static fn (string $value): ?string => empty($value) ? null : $value;

        $fileData = file_get_contents($file);

        $contextBuilder = (new ObjectNormalizerContextBuilder())
            ->withCallbacks([
                'address' => $emptyToNull,
                'city' => $emptyToNull,
                'state' => $emptyToNull,
                'zip' => $emptyToNull,
                'phone' => $emptyToNull,
                'email' => $emptyToNull,
                'unitSize' => self::formatUnitSize(...),
            ])
        ;

        $lines = $this->serializer->deserialize($fileData, PayerLine::class . '[]', 'csv', $contextBuilder->toArray());
        Assert::isArray($lines);
        Assert::allIsInstanceOf($lines, PayerLine::class);

        return $lines;
    }
}
