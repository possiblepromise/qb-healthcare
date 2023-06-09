<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use PossiblePromise\QbHealthcare\ValueObject\AppointmentLine;
use PossiblePromise\QbHealthcare\ValueObject\ImportedRecords;
use QuickBooksOnline\API\Data\IPPJournalEntry;

final class AppointmentsRepository extends MongoRepository
{
    use QbApiTrait;

    private Collection $appointments;
    private Collection $completedAppointments;

    public function __construct(MongoClient $client, private readonly PayersRepository $payers, private ChargesRepository $charges, QuickBooks $qb)
    {
        $this->appointments = $client->getDatabase()->selectCollection('appointments');
        $this->completedAppointments = $client->getDatabase()->selectCollection('completedAppointments');
        $this->qb = $qb;
    }

    /**
     * @param AppointmentLine[] $lines
     */
    public function import(array $lines): ImportedRecords
    {
        $imported = new ImportedRecords();

        foreach ($lines as $line) {
            // Don't care about canceled appointments
            if ($line->status !== 'Active'
            || $line->units === null
            || $line->charge === null) {
                continue;
            }

            $payer = $this->payers->findOneByNameAndService($line->payerName, $line->billingCode);
            if ($payer === null) {
                throw new \UnexpectedValueException('No payer found');
            }

            $appointment = new Appointment(
                id: $line->id,
                payer: $payer,
                serviceDate: $line->serviceDate,
                clientName: $line->clientName,
                units: $line->units,
                charge: $line->charge,
                dateBilled: $line->dateBilled,
                completed: $line->completed,
            );

            $appointment->setQbCompanyId($this->qb->getActiveCompany(true)->realmId);

            $result = $this->appointments->updateOne(
                ['_id' => $appointment->getId()],
                ['$set' => $appointment],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
            $imported->modified += $result->getModifiedCount() ?? 0;
        }

        return $imported;
    }

    /**
     * @return Appointment[]
     */
    public function findByChargeData(
        string $payerName,
        \DateTime $serviceDate,
        string $clientName,
        string $billingCode,
    ): array {
        $result = $this->completedAppointments->find([
            'serviceDate' => new UTCDateTime($serviceDate),
            'clientName' => $clientName,
            'payer.name' => $payerName,
            'payer.services._id' => $billingCode,
            'chargeId' => null,
        ]);

        return self::getArrayFromResult($result);
    }

    public function findMatches(): int
    {
        $charges = $this->charges->findWithoutAppointments();

        $matchedAppointments = 0;

        foreach ($charges as $charge) {
            $matchedAppointments += $this->matchCharge($charge);
        }

        return $matchedAppointments;
    }

    /**
     * @return Appointment[]
     */
    public function findUnbilledWithoutJournalEntries(): array
    {
        $result = $this->completedAppointments->aggregate([
            self::getClaimsLookup(),
            ['$match' => [
                'claims' => ['$size' => 0],
                'qbJournalEntryId' => null,
            ]],
            ['$sort' => self::getDefaultSort()],
        ]);

        return self::getArrayFromResult($result);
    }

    /**
     * @return Appointment[]
     */
    public function findUnbilledAsOf(\DateTime $date): array
    {
        $result = $this->completedAppointments->aggregate([
            self::getClaimsLookup(),
            ['$match' => [
                'claims' => ['$size' => 0],
                'serviceDate' => [
                    '$lte' => new UTCDateTime($date),
                ],
            ]],
            ['$sort' => self::getDefaultSort()],
        ]);

        return self::getArrayFromResult($result);
    }

    /**
     * @return Appointment[]
     */
    public function findIncompleteAsOf(\DateTimeInterface $date): array
    {
        $result = $this->appointments->aggregate([
            ['$match' => [
                'completed' => false,
                'serviceDate' => [
                    '$lte' => new UTCDateTime($date),
                ],
            ]],
            ['$sort' => self::getDefaultSort()],
        ]);

        return self::getArrayFromResult($result);
    }

    /**
     * @param Charge[] $charges
     *
     * @return Appointment[]
     */
    public function findUnbilledFromCharges(array $charges): array
    {
        $chargeIds = (new FilterableArray($charges))->map(
            static fn (Charge $charge): string => $charge->getChargeLine()
        );

        $result = $this->completedAppointments->aggregate([
            self::getClaimsLookup(),
            ['$match' => [
                'claims' => ['$size' => 0],
                'chargeId' => [
                    '$in' => $chargeIds,
                ],
            ]],
            ['$sort' => self::getDefaultSort()],
        ]);

        return self::getArrayFromResult($result);
    }

    public function getNextClaimDate(): ?\DateTime
    {
        $result = $this->completedAppointments->aggregate([
            self::getClaimsLookup(),
            ['$match' => [
                'claims' => ['$size' => 0],
                'dateBilled' => ['$ne' => null],
            ]],
            ['$sort' => ['dateBilled' => 1]],
            ['$project' => ['dateBilled' => true]],
        ]);

        $result->next();

        if (!$result->valid()) {
            return null;
        }

        $record = $result->current();

        return $record['dateBilled']->toDateTime();
    }

    private function matchCharge(Charge $charge): int
    {
        $appointments = $this->findByChargeData(
            payerName: $charge->getPrimaryPaymentInfo()->getPayer()->getName(),
            serviceDate: $charge->getServiceDate(),
            clientName: $charge->getClientName(),
            billingCode: $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0]->getBillingCode()
        );

        if (empty($appointments)) {
            return 0;
        }

        $unitsLeft = $charge->getBilledUnits();

        foreach ($appointments as $appointment) {
            $unitsLeft -= $appointment->getUnits();

            if ($appointment->getUnits() === $charge->getBilledUnits()) {
                $this->setChargeId($appointment, $charge);

                return 1;
            }
        }

        if ($unitsLeft !== 0) {
            // Can't handle this case
            return 0;
        }

        // Multiple appointments match so assign the charge to each of them
        foreach ($appointments as $appointment) {
            $this->setChargeId($appointment, $charge);
        }

        return \count($appointments);
    }

    public function setQbJournalEntry(Appointment $appointment, IPPJournalEntry $entry): void
    {
        $this->appointments->updateOne(
            ['_id' => $appointment->getId()],
            ['$set' => ['qbJournalEntryId' => $entry->Id]],
        );
    }

    public function setQbReversingJournalEntry(Appointment $appointment, IPPJournalEntry $entry): void
    {
        $this->appointments->updateOne(
            ['_id' => $appointment->getId()],
            ['$set' => ['qbReversingJournalEntryId' => $entry->Id]],
        );
    }

    private function setChargeId(Appointment $appointment, Charge $charge): void
    {
        $this->appointments->updateOne(
            ['_id' => $appointment->getId()],
            ['$set' => ['chargeId' => $charge->getChargeLine()]],
        );
    }

    private static function getClaimsLookup(): array
    {
        return [
            '$lookup' => [
                'from' => 'claims',
                'localField' => 'chargeId',
                'foreignField' => 'charges',
                'as' => 'claims',
            ],
        ];
    }

    private static function getDefaultSort(): array
    {
        return [
            'serviceDate' => 1,
            '_id' => 1,
        ];
    }
}
