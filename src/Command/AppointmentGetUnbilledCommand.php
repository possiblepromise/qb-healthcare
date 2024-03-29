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
    name: 'appointment:get-unbilled',
    description: 'Gets the total of all unbilled appointments.'
)]
final class AppointmentGetUnbilledCommand extends Command
{
    public function __construct(private AppointmentsRepository $appointments)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to retrieve the total of all unbilled appointments.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Get Unbilled Appointments');

        $unbilledAppointments = $this->appointments->findUnbilled();

        if (empty($unbilledAppointments)) {
            $io->success('There are currently no unbilled appointments.');

            return Command::SUCCESS;
        }

        $headers = [
            'Date',
            'Service',
            'Units',
            'Rate',
            'Charge',
        ];

        $rows = [];
        $sum = '0.00';
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        foreach ($unbilledAppointments as $appointment) {
            $sum = bcadd($sum, $appointment->getCharge(), 2);

            $rows[] = [
                $appointment->getServiceDate()->format('Y-m-d'),
                $appointment->getPayer()->getServices()[0]->getName(),
                $appointment->getUnits(),
                $fmt->formatCurrency((float) $appointment->getPayer()->getServices()[0]->getRate(), 'USD'),
                $fmt->formatCurrency((float) $appointment->getCharge(), 'USD'),
            ];
        }

        $io->text(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, ' .
            'one {There is # unbilled appointment} ' .
            'other {There are # unbilled appointments}' .
        '} for a total of {1, number, :: currency/USD}.',
            [\count($unbilledAppointments), $sum]
        ));
        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
