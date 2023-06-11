<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\Entity\Charge;
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

            $appointment = new Appointment(
                id: $line->id,
                payer: $payer,
                service: $service,
                serviceDate: $line->serviceDate,
                clientName: $line->clientName,
                units: $line->units,
                charge: $line->charge,
                dateBilled: $line->dateBilled,
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
        $result = $this->appointments->find([
            'serviceDate' => new UTCDateTime($serviceDate),
            'clientName' => $clientName,
            'payer.name' => $payerName,
            'payer.services._id' => $billingCode,
            'chargeId' => null,
        ]);

        $appointments = [];

        foreach ($result as $appointment) {
            $appointments[] = $appointment;
        }

        return $appointments;
    }

    public function findByChargeId(string $chargeId): ?Appointment
    {
        return $this->appointments->findOne([
            'chargeId' => $chargeId,
        ]);
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

    private function setChargeId(Appointment $appointment, Charge $charge): void
    {
        $this->appointments->updateOne(
            ['_id' => $appointment->getId()],
            ['$set' => ['chargeId' => $charge->getChargeLine()]],
        );
    }
}
