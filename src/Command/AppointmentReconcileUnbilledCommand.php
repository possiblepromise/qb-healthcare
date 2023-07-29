<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\JournalEntriesRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appointment:reconcile-unbilled',
    description: 'Reconciles unbilled appointments against their journal entries.'
)]
final class AppointmentReconcileUnbilledCommand extends Command
{
    public function __construct(private AppointmentsRepository $appointments, private JournalEntriesRepository $journalEntries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to reconcile unbilled appointments against their journal entries in QuickBooks.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Reconcile Unbilled Appointments');

        $unbilledAppointments = $this->appointments->findUnbilled();

        if (empty($unbilledAppointments)) {
            $io->success('There are currently no unbilled appointments.');

            return Command::SUCCESS;
        }

        $io->progressStart(\count($unbilledAppointments));

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $rows = [];

        foreach ($unbilledAppointments as $appointment) {
            if ($appointment->getQbJournalEntryId() === null) {
                $io->progressAdvance();

                continue;
            }

            $entry = $this->journalEntries->get($appointment->getQbJournalEntryId());

            if (bccomp($entry->Line[0]->Amount, $appointment->getCharge(), 2) !== 0) {
                $rows[] = [
                    $appointment->getServiceDate()->format('Y-m-d'),
                    $entry->DocNumber,
                    $fmt->formatCurrency((float) $appointment->getCharge(), 'USD'),
                    $fmt->formatCurrency((float) $entry->Line[0]->Amount, 'USD'),
                ];
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if (!empty($rows)) {
            $io->error('Some appointments do not reconcile.');

            $io->table(
                ['Date', 'Journal Entry Ref', 'Expected', 'Actual'],
                $rows
            );

            return Command::FAILURE;
        }

        $io->success('All unbilled appointments have been reconciled successfully.');

        return Command::SUCCESS;
    }
}
