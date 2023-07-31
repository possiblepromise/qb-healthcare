<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\JournalEntriesRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Date to start reconciling')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'Date up to which to reconcile')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Reconcile Unbilled Appointments');

        $startDate = null;
        if ($input->getOption('start') !== null) {
            $startDate = new \DateTimeImmutable($input->getOption('start'));
        }

        $endDate = null;
        if ($input->getOption('end')) {
            $endDate = new \DateTimeImmutable($input->getOption('end'));
        }

        if ($startDate || $endDate) {
            if ($startDate !== null) {
                $io->text('<options=bold>Start reconciling</>: ' . $startDate->format('Y-m-d'));
            }

            if ($endDate !== null) {
                $io->text('<options=bold>Reconcile through</>: ' . $endDate->format('Y-m-d'));
            }

            $io->newLine();
        }

        $appointments = $this->appointments->findReconcilable($endDate);

        if (empty($appointments)) {
            $io->success('There are currently no appointments to reconcile.');

            return Command::SUCCESS;
        }

        $startBalance = '0.00';
        $endBalance = '0.00';

        foreach ($appointments as $appointment) {
            if ($startDate !== null && $appointment->getServiceDate() < $startDate) {
                $startBalance = bcadd($startBalance, $appointment->getCharge(), 2);

                if ($appointment->getQbReversingJournalEntryId() !== null && $appointment->getDateBilled() < $startDate) {
                    $startBalance = bcsub($startBalance, $appointment->getCharge(), 2);
                }
            }

            if ($endDate === null || $appointment->getServiceDate() <= $endDate) {
                $endBalance = bcadd($endBalance, $appointment->getCharge(), 2);
            }
            if ($appointment->getQbReversingJournalEntryId() !== null && ($endDate === null || $appointment->getDateBilled() < $endDate)) {
                $endBalance = bcsub($endBalance, $appointment->getCharge(), 2);
            }
        }

        // Verify journal entries that have not been reversed
        $errors = $this->reconcileJournalEntries($appointments, $io);

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        if ($startDate !== null) {
            $io->text('<options=bold>Starting balance</>: ' . $fmt->formatCurrency((float) $startBalance, 'USD'));
        }

        $io->text('<options=bold>Ending balance</>: ' . $fmt->formatCurrency((float) $endBalance, 'USD'));
        $io->newLine();

        if (!empty($errors)) {
            $io->error('Some appointments do not reconcile.');

            $io->table(
                ['Date', 'Journal Entry Ref', 'Expected', 'Actual'],
                $errors
            );

            return Command::FAILURE;
        }

        $io->success('All unbilled appointments have been reconciled successfully.');

        return Command::SUCCESS;
    }

    /**
     * @param Appointment[] $appointments
     */
    private function reconcileJournalEntries(array $appointments, SymfonyStyle $io): array
    {
        $unbilledAppointments = array_filter(
            $appointments,
            static fn (Appointment $appointment): bool => $appointment->getQbReversingJournalEntryId() === null
        );

        if (empty($unbilledAppointments)) {
            return [];
        }

        $io->progressStart(\count($unbilledAppointments));

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $rows = [];

        foreach ($unbilledAppointments as $appointment) {
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

        return $rows;
    }
}
