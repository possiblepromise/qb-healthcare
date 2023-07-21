<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appointment:get-incomplete',
    description: 'Gets the total of all incomplete appointments.'
)]
final class AppointmentGetIncomplete extends Command
{
    public function __construct(private AppointmentsRepository $appointments)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to retrieve the total of all incomplete appointments.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Incomplete Appointments');

        $incompleteAppointments = $this->appointments->findIncomplete();

        if (empty($incompleteAppointments)) {
            $io->success('There are currently no incomplete appointments.');

            return Command::SUCCESS;
        }

        $headers = [
            'Date',
            'Service',
            'Client',
            'Charge',
        ];

        $rows = [];
        $sum = '0.00';
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        foreach ($incompleteAppointments as $appointment) {
            $sum = bcadd($sum, $appointment->getCharge(), 2);

            $rows[] = [
                $appointment->getServiceDate()->format('Y-m-d'),
                $appointment->getPayer()->getServices()[0]->getName(),
                $appointment->getClientName(),
                $fmt->formatCurrency((float) $appointment->getCharge(), 'USD'),
            ];
        }

        $io->text(sprintf(
            'There are %d incomplete appointments for a total of %s.',
            \count($incompleteAppointments),
            $fmt->formatCurrency((float) $sum, 'USD')
        ));
        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
