<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

use Uhin\X12Parser\EDI\Segments\HL;
use Uhin\X12Parser\EDI\Segments\Segment;
use Uhin\X12Parser\EDI\Segments\ST;
use Uhin\X12Parser\EDI\X12;
use Uhin\X12Parser\Parser\X12Parser;

final class Edi837Reader
{
    private X12 $x12;
    /**
     * @var Edi837Claim[]
     */
    private array $claims = [];

    public function __construct(string $file)
    {
        $data = self::removeBom(file_get_contents($file));

        $parser = new X12Parser($data);
        $this->x12 = $parser->parse();
    }

    /**
     * @return Edi837Claim[]
     */
    public function process(): array
    {
        /** @var ST $st */
        $st = $this->x12->ISA[0]->GS[0]->ST[0];

        $billedDate = null;

        /** @var Segment $segment */
        foreach ($st->properties as $segment) {
            if ($segment->getSegmentId() === 'BHT') {
                $billedDate = self::parseDate($segment->BHT04);
                break;
            }
        }

        /** @var HL $provider */
        foreach ($st->HL as $provider) {
            /** @var HL $subscriber */
            foreach ($provider->HL as $subscriber) {
                $this->processSubscriber($subscriber, $billedDate);
            }
        }

        return $this->claims;
    }

    private function processSubscriber(HL $subscriber, \DateTimeImmutable $billedDate): void
    {
        $claimIndex = -1;
        $chargeIndex = -1;

        $firstName = null;
        $lastName = null;
        $payerId = null;

        /** @var Segment $segment */
        foreach ($subscriber->properties as $segment) {
            $segmentId = $segment->getSegmentId();

            switch ($segmentId) {
                case 'NM1':
                    if ($segment->NM101 === 'IL') {
                        $lastName = $segment->NM103;
                        $firstName = $segment->NM104;
                    } elseif ($segment->NM101 === 'PR') {
                        $payerId = $segment->NM109;
                    }
                    break;

                case 'CLM':
                    $claimIndex++;
                    $chargeIndex = -1;

                    $claim = new Edi837Claim();
                    $claim->payerId = $payerId;
                    $claim->billedDate = $billedDate;
                    $claim->clientLastName = $lastName;
                    $claim->clientFirstName = $firstName;
                    $claim->billed = $segment->CLM02;

                    $this->claims[$claimIndex] = $claim;
                    break;

                case 'LX':
                    $chargeIndex++;
                    $this->claims[$claimIndex]->charges[$chargeIndex] = new Edi837Charge();
                    break;

                case 'SV1':
                    $this->claims[$claimIndex]->charges[$chargeIndex]->billingCode = $segment->SV101[0][1];
                    $this->claims[$claimIndex]->charges[$chargeIndex]->billed = $segment->SV102;
                    $this->claims[$claimIndex]->charges[$chargeIndex]->units = (int) $segment->SV104;
                    break;

                case 'DTP':
                    if ($segment->DTP01 === '472') {
                        $this->claims[$claimIndex]->charges[$chargeIndex]->serviceDate = self::parseDate($segment->DTP03);
                    }
                    break;
            }
        }
    }

    private static function removeBom(string $data): string
    {
        $bom = pack('H*', 'EFBBBF');

        return preg_replace("/^{$bom}/", '', $data);
    }

    private static function parseDate(string $ediDate): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('Ymd', $ediDate)->modify('00:00:00');
    }
}
