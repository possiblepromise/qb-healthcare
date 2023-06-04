<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare;

use PossiblePromise\QbHealthcare\ValueObject\HcfaInfo;

final class HcfaReader
{
    public function read(string $file): HcfaInfo
    {
        $data = file_get_contents($file);

        $matched = preg_match_all('/\d+\s+[^(]+\((?<payerId>\d+)\)\s+(?<claims>\d+)\s+(?<total>\$[0-9,.]+)/', $data, $matches, PREG_SET_ORDER);

        if ($matched === 0) {
            self::throwInvalidFileError();
        }

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $payerId = $matches[0]['payerId'];
        $total = (string) $fmt->parseCurrency($matches[0]['total'], $currency);
        \assert(is_numeric($total));

        $matched = preg_match('/File Name:\s*(?<fileId>\d+)/', $data, $matches);
        if ($matched === 0) {
            self::throwInvalidFileError();
        }

        $fileId = $matches['fileId'];

        $matched = preg_match_all(
            '/\s+\d+\)\s+(?<claimId>\d+)\s+[^ ]+\s+(?<lastName>[^ ]+)\s+(?<firstName>.+?)\s*\d{2}\/\d{2}\/\d{4}\s+(?<fromDate>\d{2}\/\d{2}\/\d{4})\s+(?<toDate>\d{2}\/\d{2}\/\d{4})/',
            $data,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched === 0) {
            self::throwInvalidFileError();
        }

        $claimId = $matches[0]['claimId'];
        $lastName = $matches[0]['lastName'];
        $firstName = $matches[0]['firstName'];
        $fromDate = \DateTimeImmutable::createFromFormat('m/d/Y', $matches[0]['fromDate']);
        if ($fromDate === false) {
            self::throwInvalidFileError();
        }

        $toDate = \DateTimeImmutable::createFromFormat('m/d/Y', $matches[0]['toDate']);
        if ($toDate === false) {
            self::throwInvalidFileError();
        }

        return new HcfaInfo(
            fileId: $fileId,
            payerId: $payerId,
            total: $total,
            claimId: $claimId,
            lastName: $lastName,
            firstName: $firstName,
            fromDate: $fromDate->modify('00:00:00'),
            toDate: $toDate->modify('00:00:00')
        );
    }

    /**
     * @psalm-return never
     */
    private static function throwInvalidFileError(): void
    {
        throw new \InvalidArgumentException('Invalid format for HCFA file.');
    }
}
