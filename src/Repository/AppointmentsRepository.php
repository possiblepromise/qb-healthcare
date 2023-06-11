<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\ValueObject\AppointmentLine;
use PossiblePromise\QbHealthcare\ValueObject\ImportedRecords;

final class AppointmentsRepository
{
    use QbApiTrait;

    private Collection $appointments;

    public function __construct(MongoClient $client, private readonly PayersRepository $payers, private ChargesRepository $charges, QuickBooks $qb)
    {
        $this->appointments = $client->getDatabase()->appointments;
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
            if ($line->completed === false
            || $line->units === null
            || $line->charge === null) {
                continue;
            }

            $payer = $this->payers->findOneByNameAndService($line->payerName, $line->billingCode);
            if ($payer === null) {
                throw new \UnexpectedValueException('No payer found');
            }

            $service = $payer->getServices()[0];

            $charge = $this->charges->findByAppointmentData(
                payerName: $line->payerName,
                serviceDate: $line->serviceDate,
                clientName: $line->clientName,
                billingCode: $line->billingCode,
                billingUnits: $line->units
            );

            $appointment = new Appointment(
                id: $line->id,
                payer: $payer,
                service: $service,
                serviceDate: $line->serviceDate,
                clientName: $line->clientName,
                units: $line->units,
                charge: $line->charge,
                dateBilled: $line->dateBilled,
                chargeId: $charge ? $charge->getChargeLine() : null
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
}
