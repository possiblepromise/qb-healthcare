<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPJournalEntry;
use QuickBooksOnline\API\Facades\JournalEntry;

final class JournalEntriesRepository
{
    use QbApiTrait;

    private \QuickBooksOnline\API\DataService\DataService $dataService;

    public function __construct(QuickBooks $qb, private ItemsRepository $items)
    {
        $this->qb = $qb;
        $this->dataService = $this->qb->getDataService();
    }

    public function createAccruedRevenueEntryFromAppointment(Appointment $appointment, string $docNumber): IPPJournalEntry
    {
        $service = $this->items->get($appointment->getPayer()->getServices()[0]->getQbItemId());
        $debitAccount = $this->qb->getActiveCompany()->accruedRevenueAccount;
        /** @var string $creditAccount */
        $creditAccount = $service->IncomeAccountRef;

        $succeeded = false;

        while ($succeeded === false) {
            $entry = $this->createJournalEntryObject($docNumber, $appointment->getServiceDate(), $appointment, $debitAccount, $creditAccount);
            $result = $this->dataService->add($entry);
            $error = $this->dataService->getLastError();
            if ($error) {
                if ($error->getIntuitErrorCode() === '6140') {
                    $docNumber = bcadd($docNumber, '1', 0);

                    continue;
                }
                $this->throwIntuitError($error);
            }

            $succeeded = true;
        }

        return $result;
    }

    public function createReversingAccruedRevenueEntryFromAppointment(Appointment $appointment): IPPJournalEntry
    {
        if ($appointment->getQbJournalEntryId() === null) {
            throw new \RuntimeException(sprintf('Appointment %s does not have a journal entry to reverse.', $appointment->getId()));
        }
        if ($appointment->getQbReversingJournalEntryId() !== null) {
            throw new \RuntimeException(sprintf('Appointment %s already has a reversing journal entry.', $appointment->getId()));
        }

        $service = $this->items->get($appointment->getPayer()->getServices()[0]->getQbItemId());
        /** @var string $creditAccount */
        $debitAccount = $service->IncomeAccountRef;
        $creditAccount = $this->qb->getActiveCompany()->accruedRevenueAccount;

        $originalEntry = $this->dataService->FindById('JournalEntry', $appointment->getQbJournalEntryId());
        $docNumber = "{$originalEntry->DocNumber}R";

        $entry = $this->createJournalEntryObject($docNumber, $appointment->getDateBilled(), $appointment, $debitAccount, $creditAccount);

        $result = $this->dataService->add($entry);
        $error = $this->dataService->getLastError();

        if ($error) {
            $this->throwIntuitError($error);
        }

        return $result;
    }

    private function createJournalEntryObject(
        string $docNumber,
        \DateTime $date,
        Appointment $appointment,
        string $debitAccount,
        string $creditAccount,
    ): IPPJournalEntry {
        $amount = $appointment->getCharge();
        $description = $appointment->getId();
        $customer = $appointment->getPayer()->getQbCustomerId();

        return JournalEntry::create([
            'Line' => [
                self::createCreditLine($creditAccount, $amount, $description, $customer),
                self::createDebitLine($debitAccount, $amount, $description, $customer),
            ],
            'DocNumber' => $docNumber,
            'TxnDate' => $date->format('Y-m-d'),
            'Adjustment' => true,
        ]);
    }

    private static function createCreditLine(
        string $account,
        string $amount,
        string $description,
        string $customer
    ): array {
        return [
            'JournalEntryLineDetail' => [
                'PostingType' => 'Credit',
                'AccountRef' => [
                    'value' => $account,
                ],
                'Entity' => [
                    'EntityRef' => [
                        'value' => $customer,
                    ],
                ],
            ],
            'DetailType' => 'JournalEntryLineDetail',
            'Amount' => $amount,
            'Description' => $description,
        ];
    }

    private static function createDebitLine(
        string $account,
        string $amount,
        string $description,
        string $customer
    ): array {
        return [
            'JournalEntryLineDetail' => [
                'PostingType' => 'Debit',
                'AccountRef' => [
                    'value' => $account,
                ],
                'Entity' => [
                    'EntityRef' => [
                        'value' => $customer,
                    ],
                ],
            ],
            'DetailType' => 'JournalEntryLineDetail',
            'Amount' => $amount,
            'Description' => $description,
        ];
    }
}
