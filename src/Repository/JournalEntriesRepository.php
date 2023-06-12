<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPItem;
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

        $succeeded = false;

        while ($succeeded === false) {
            $entry = $this->createJournalEntryObject($service, $appointment, $docNumber);
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

    private function createJournalEntryObject(
        IPPItem $service,
        Appointment $appointment,
        string $docNumber
    ): IPPJournalEntry {
        return JournalEntry::create([
            'Line' => [
                [
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Credit',
                        'AccountRef' => [
                            'value' => $service->IncomeAccountRef,
                        ],
                        'Entity' => [
                            'EntityRef' => [
                                'value' => $appointment->getPayer()->getQbCustomerId(),
                            ],
                        ],
                    ],
                    'DetailType' => 'JournalEntryLineDetail',
                    'Amount' => $appointment->getCharge(),
                    'Description' => $appointment->getId(),
                ],
                [
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Debit',
                        'AccountRef' => [
                            'value' => $this->qb->getActiveCompany()->getAccruedRevenueAccount(),
                        ],
                        'Entity' => [
                            'EntityRef' => [
                                'value' => $appointment->getPayer()->getQbCustomerId(),
                            ],
                        ],
                    ],
                    'DetailType' => 'JournalEntryLineDetail',
                    'Amount' => $appointment->getCharge(),
                    'Description' => $appointment->getId(),
                ],
            ],
            'DocNumber' => $docNumber,
            'TxnDate' => $appointment->getServiceDate()->format('Y-m-d'),
            'Adjustment' => true,
        ]);
    }
}
