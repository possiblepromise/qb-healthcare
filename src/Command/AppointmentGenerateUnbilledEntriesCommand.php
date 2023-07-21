<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Repository\AccountsRepository;
use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\JournalEntriesRepository;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appointment:generate-unbilled-entries',
    description: 'Generates journal entries for unbilled appointments.'
)]
final class AppointmentGenerateUnbilledEntriesCommand extends Command
{
    public function __construct(
        private AppointmentsRepository $appointments,
        private AccountsRepository $accounts,
        private JournalEntriesRepository $journalEntries,
        private QuickBooks $qb
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to generate adjusting journal entries for unbilled appointments at the end of a billing period.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Generate Entries for Unbilled Appointments');

        if ($this->qb->getActiveCompany(true)->accruedRevenueAccount === null) {
            $this->configureAccruedRevenueAccount($io);
        }

        $unbilledAppointments = $this->appointments->findUnbilledWithoutJournalEntries();

        if (empty($unbilledAppointments)) {
            $io->success('There are currently no unbilled appointments.');

            return Command::SUCCESS;
        }

        $io->text(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, ' .
                'one {There is # unbilled appointment.} ' .
                'other {There are # unbilled appointments.}' .
                '}',
            [\count($unbilledAppointments)]
        ));

        $startingRef = $io->ask('Please enter starting ref number', null, static function (string $value): string {
            if (!ctype_digit($value)) {
                throw new \InvalidArgumentException('Ref number must be an integer.');
            }

            return $value;
        });

        $entriesCreated = 0;

        foreach ($unbilledAppointments as $appointment) {
            $entry = $this->journalEntries->createAccruedRevenueEntryFromAppointment(
                $appointment,
                $startingRef
            );

            $this->appointments->setQbJournalEntry($appointment, $entry);

            $io->text(sprintf(
                'Created journal entry %s for appointment %s on %s',
                $entry->DocNumber,
                $appointment->getId(),
                $appointment->getServiceDate()->format('Y-m-d')
            ));

            $startingRef = bcadd($entry->DocNumber, '1', 0);
            ++$entriesCreated;
        }

        $io->success(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, one {Created a journal entry for # unbilled appointment} other {Created journal entries for # unbilled appointments}}',
            [$entriesCreated]
        ));

        return Command::SUCCESS;
    }

    private function configureAccruedRevenueAccount(SymfonyStyle $io): void
    {
        $io->caution('The accrued revenue account is not configured. Please select it below.');

        $accounts = new FilterableArray($this->accounts->findAllOtherCurrentAssetAccounts());
        $accountName = $io->choice(
            'Accrued revenue account',
            $accounts->map(static fn (IPPAccount $account) => $account->FullyQualifiedName)
        );
        $account = $accounts->selectOne(
            static fn (IPPAccount $account) => $account->FullyQualifiedName === $accountName
        );
        $this->qb->getActiveCompany()->accruedRevenueAccount = $account->Id;
        $this->qb->save();
    }
}
