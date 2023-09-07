<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

use PossiblePromise\QbHealthcare\Exception\EdiException;
use Uhin\X12Parser\EDI\Segments\GS;
use Uhin\X12Parser\EDI\Segments\HL;
use Uhin\X12Parser\EDI\Segments\ISA;
use Uhin\X12Parser\EDI\Segments\Segment;
use Uhin\X12Parser\EDI\Segments\ST;
use Uhin\X12Parser\EDI\X12;
use Uhin\X12Parser\Parser\X12Parser;

final class Edi837Reader
{
    private X12 $x12;

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
        $claims = [];

        /** @var ISA $isa */
        foreach ($this->x12->ISA as $isa) {
            $this->processInterchangeControl($isa, $claims);
        }

        return $claims;
    }

    /**
     * @param Edi837Claim[] $claims
     */
    private function processInterchangeControl(ISA $isa, array &$claims): void
    {
        /** @var GS $gs */
        foreach ($isa->GS as $gs) {
            $this->processFunctionalGroup($gs, $claims);
        }

        if ((int) $isa->IEA->IEA01 !== \count($isa->GS)) {
            throw new EdiException('The number of functional groups do not match.');
        }

        if ($isa->IEA->IEA02 !== $isa->ISA13) {
            throw new EdiException('The interchange control numbers do not match.');
        }
    }

    /**
     * @param Edi837Claim[] $claims
     */
    private function processFunctionalGroup(GS $gs, array &$claims): void
    {
        /** @var ST $st */
        foreach ($gs->ST as $st) {
            $this->processTransactionSet($st, $claims);
        }

        if ((int) $gs->GE->GE01 !== \count($gs->ST)) {
            throw new EdiException('The number of transaction sets do not match.');
        }

        if ($gs->GE->GE02 !== $gs->GS06) {
            throw new EdiException('The group control numbers do not match.');
        }
    }

    /**
     * @param Edi837Claim[] $claims
     */
    private function processTransactionSet(ST $st, array &$claims): void
    {
        if ($st->ST01 !== '837') {
            throw new EdiException('This is not an EDI 837 file.');
        }

        $billedDate = null;

        /** @var Segment $segment */
        foreach ($st->properties as $segment) {
            if ($segment->getSegmentId() === 'BHT') {
                $billedDate = self::parseDate($segment->BHT04);
            } elseif ($segment->getSegmentId() === 'SE') {
                if ($segment->SE02 !== $st->ST02) {
                    throw new EdiException('The transaction set control numbers do not match.');
                }
            }
        }

        /** @var HL $provider */
        foreach ($st->HL as $provider) {
            /** @var HL $subscriber */
            foreach ($provider->HL as $subscriber) {
                $this->processSubscriber($subscriber, $billedDate, $claims);
            }
        }
    }

    /**
     * @param Edi837Claim[] $claims
     */
    private function processSubscriber(HL $subscriber, \DateTimeImmutable $billedDate, array &$claims): void
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

                    $claims[$claimIndex] = $claim;
                    break;

                case 'LX':
                    $chargeIndex++;
                    $claims[$claimIndex]->charges[$chargeIndex] = new Edi837Charge();
                    break;

                case 'SV1':
                    $claims[$claimIndex]->charges[$chargeIndex]->billingCode = $segment->SV101[0][1];
                    $claims[$claimIndex]->charges[$chargeIndex]->billed = $segment->SV102;
                    $claims[$claimIndex]->charges[$chargeIndex]->units = (int) $segment->SV104;
                    break;

                case 'DTP':
                    if ($segment->DTP01 === '472') {
                        $claims[$claimIndex]->charges[$chargeIndex]->serviceDate = self::parseDate($segment->DTP03);
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
