<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Edi;

use PossiblePromise\QbHealthcare\Entity\ProviderAdjustment;
use PossiblePromise\QbHealthcare\Entity\ProviderAdjustmentType;
use PossiblePromise\QbHealthcare\Exception\EdiException;
use Uhin\X12Parser\EDI\Segments\GS;
use Uhin\X12Parser\EDI\Segments\ISA;
use Uhin\X12Parser\EDI\Segments\Segment;
use Uhin\X12Parser\EDI\Segments\ST;
use Uhin\X12Parser\EDI\X12;
use Uhin\X12Parser\Parser\X12Parser;

final class Edi835Reader
{
    private X12 $x12;

    public function __construct(string $file)
    {
        $data = self::readFileData($file);

        $parser = new X12Parser($data);
        $this->x12 = $parser->parse();
    }

    /**
     * @return Edi835Payment[]
     */
    public function process(): array
    {
        $payments = [];

        /** @var ISA $isa */
        foreach ($this->x12->ISA as $isa) {
            /** @var GS $gs */
            foreach ($isa->GS as $gs) {
                /** @var ST $st */
                foreach ($gs->ST as $st) {
                    $payments[] = self::processTransactionSet($st);
                }

                if ((int) $gs->GE->GE01 !== \count($payments)) {
                    throw new EdiException('The number of transaction sets do not match.');
                }
                if ($gs->GE->GE02 !== $gs->GS06) {
                    throw new EdiException('The group control numbers do not match.');
                }
            }

            if ($isa->IEA->IEA02 !== $isa->ISA13) {
                throw new EdiException('The interchange control numbers do not match.');
            }
        }

        return $payments;
    }

    private static function processTransactionSet(ST $st): Edi835Payment
    {
        if ($st->ST01 !== '835') {
            throw new EdiException('This is not an EDI 835 file.');
        }

        $payment = new Edi835Payment();
        $claimIndex = -1;
        $currentClaim = null;
        $chargeIndex = -1;
        $currentCharge = null;
        $claimStartDate = null;
        $claimEndDate = null;

        /** @var Segment $segment */
        foreach ($st->properties as $segment) {
            switch ($segment->getSegmentId()) {
                case 'BPR':
                    $payment->payment = $segment->BPR02;
                    $payment->paymentDate = self::parseDate($segment->BPR16);
                    break;

                case 'TRN':
                    $payment->paymentRef = $segment->TRN02;
                    break;

                case 'N1':
                    if ($segment->N101 === 'PR') {
                        $payment->payerName = $segment->N102;
                    }
                    break;

                case 'CLP':
                    $claim = new Edi835ClaimPayment();
                    $claim->amountClaimed = $segment->CLP03;
                    $claim->amountPaid = $segment->CLP04;
                    $claim->patientResponsibility = $segment->CLP05 ?: '0.00';

                    $payment->claims[++$claimIndex] = $claim;
                    $currentClaim = $claim;

                    // Set start and end date to null so we're not getting old data
                    $claimStartDate = null;
                    $claimEndDate = null;
                    break;

                case 'NM1':
                    if ($segment->NM101 === 'QC') {
                        $currentClaim->clientLastName = $segment->NM103;
                        $currentClaim->clientFirstName = $segment->NM104;
                    }
                    break;

                case 'SVC':
                    $charge = new Edi835ChargePayment();
                    $charge->billingCode = $segment->SVC01[0][1];
                    $charge->billed = $segment->SVC02;
                    $charge->paid = $segment->SVC03;
                    $charge->units = (int) $segment->SVC05;

                    if ($claimStartDate !== null
                        && $claimEndDate !== null
                        && $claimStartDate->format('Y-m-d') === $claimEndDate->format('Y-m-d')) {
                        $charge->serviceDate = clone $claimStartDate;
                    }

                    $currentClaim->charges[++$chargeIndex] = $charge;
                    $currentCharge = $charge;
                    break;

                case 'DTM':
                    if ($segment->DTM01 === '472' || $segment->DTM01 === '150') {
                        $currentCharge->serviceDate = self::parseDate($segment->DTM02);
                    } elseif ($segment->DTM01 === '232') {
                        $claimStartDate = self::parseDate($segment->DTM02);
                    } elseif ($segment->DTM01 === '233') {
                        $claimEndDate = self::parseDate($segment->DTM02);
                    }
                    break;

                case 'CAS':
                    $adjustmentType = $segment->CAS01;
                    $code = $segment->CAS02;
                    $adjustmentAmount = $segment->CAS03;

                    if ($adjustmentType === 'CO' && $code === '45') {
                        $currentCharge->contractualAdjustment = $adjustmentAmount;
                    } elseif ($adjustmentType === 'PR' && $code === '2') {
                        $currentCharge->coinsurance = $adjustmentAmount;
                    } else {
                        throw new EdiException(sprintf(
                            'Encountered unexpected adjustment: %s with code %s',
                            $adjustmentType,
                            $code
                        ));
                    }
                    break;

                case 'PLB':
                    $payment->providerAdjustments[] = self::processProviderAdjustment($segment);
                    break;

                case 'SE':
                    if ($segment->SE02 !== $st->ST02) {
                        throw new EdiException('The transaction set control numbers do not match.');
                    }
                    break 2;
            }
        }

        self::validatePayment($payment);

        return $payment;
    }

    private static function readFileData(string $file): string
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (strtolower($extension) === 'zip') {
            $zip = new \ZipArchive();
            $zip->open($file);

            $foundFile = null;

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.835')) {
                    $foundFile = $name;
                }
            }

            if ($foundFile === null) {
                throw new EdiException('No 835 file found in this zip.');
            }

            $file = "zip://{$file}#{$foundFile}";
        }

        return file_get_contents($file);
    }

    private static function parseDate(string $ediDate): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('Ymd', $ediDate)->modify('00:00:00');
    }

    private static function processProviderAdjustment(Segment $segment): ProviderAdjustment
    {
        $providerAdjustmentReason = $segment->PLB03[0][0];
        // Must reverse, because in the PLB segment, a negative amount represents a payment,
        // and a positive amount represents a withholding
        $amount = bcmul($segment->PLB04, '-1', 2);

        return match ($providerAdjustmentReason) {
            'L6' => new ProviderAdjustment(ProviderAdjustmentType::interest, $amount),
            'AH' => new ProviderAdjustment(ProviderAdjustmentType::origination_fee, $amount),
            default => throw new EdiException(sprintf(
                'Do not know how to handle provider adjustment reason %s.',
                $providerAdjustmentReason
            )),
        };
    }

    private static function validatePayment(Edi835Payment $payment): void
    {
        $total = '0.00';

        foreach ($payment->claims as $claim) {
            self::validateClaim($claim);

            $total = bcadd($total, $claim->amountPaid, 2);
        }

        foreach ($payment->providerAdjustments as $providerAdjustment) {
            $total = bcadd($total, $providerAdjustment->getAmount(), 2);
        }

        if (bccomp($total, $payment->payment, 2) !== 0) {
            throw new EdiException(sprintf(
                'Claims and other adjustments add up to %s, but %s expected.',
                $total,
                $payment->payment
            ));
        }
    }

    private static function validateClaim(Edi835ClaimPayment $claim): void
    {
        $totalBilled = '0.00';
        $totalPaid = '0.00';
        $totalPatientResponsibility = '0.00';

        foreach ($claim->charges as $charge) {
            self::validateCharge($charge);

            $totalBilled = bcadd($totalBilled, $charge->billed, 2);
            $totalPaid = bcadd($totalPaid, $charge->paid, 2);
            $totalPatientResponsibility = bcadd($totalPatientResponsibility, $charge->coinsurance, 2);
        }

        if (bccomp($totalBilled, $claim->amountClaimed, 2) !== 0) {
            throw new EdiException(sprintf(
                'Charges add up to %s, but %s expected.',
                $totalBilled,
                $claim->amountClaimed
            ));
        }

        if (bccomp($totalPaid, $claim->amountPaid, 2) !== 0) {
            throw new EdiException(sprintf(
                'Total charge payments add up to %s, but %s expected.',
                $totalPaid,
                $claim->amountPaid
            ));
        }

        if (bccomp($totalPatientResponsibility, $claim->patientResponsibility, 2) !== 0) {
            throw new EdiException(sprintf(
                'Patient responsibility adds up to %s, but %s expected.',
                $totalPatientResponsibility,
                $claim->patientResponsibility
            ));
        }
    }

    private static function validateCharge(Edi835ChargePayment $charge): void
    {
        $chargeTotalAdjustments = bcsub($charge->billed, $charge->paid, 2);
        $chargeActualAdjustments = bcadd($charge->contractualAdjustment, $charge->coinsurance, 2);

        if (bccomp($chargeTotalAdjustments, $chargeActualAdjustments, 2) !== 0) {
            throw new EdiException(sprintf(
                'Charge adjustments should total %s, but actually add up to %s.',
                $chargeTotalAdjustments,
                $chargeActualAdjustments
            ));
        }
    }
}
