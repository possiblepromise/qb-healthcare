<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Serializer\AppointmentSerializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'import:appointments',
    description: 'Import appointments from a CSV.'
)]
final class ImportAppointmentsCommand extends Command
{
    public function __construct(private AppointmentSerializer $serializer, private AppointmentsRepository $appointments)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to import new or updated appointments.')
            ->addArgument('file', InputArgument::REQUIRED, 'The CSV file to import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Import Appointments');

        $file = $input->getArgument('file');
        Assert::string($file);
        if (!file_exists($file)) {
            $io->error('Given file does not exist.');

            return Command::INVALID;
        }

        $appointmentLines = $this->serializer->unserialize($file);

        $imported = $this->appointments->import($appointmentLines);

        $io->success(
            sprintf(
                'Imported %d appointments: %d new and %d modified',
                $imported->new + $imported->modified,
                $imported->new,
                $imported->modified
            )
        );

        $deleted = $this->appointments->deleteInactive($appointmentLines);

        if ($deleted > 0) {
            $io->success(sprintf(
                'Deleted %d appointments',
                $deleted
            ));
        }

        $matchedAppointments = $this->appointments->findMatches();

        if ($matchedAppointments !== 0) {
            $io->success("Matched {$matchedAppointments} appointments to charges.");
        }

        return Command::SUCCESS;
    }
}
