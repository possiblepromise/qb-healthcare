<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appointment:get-uncompleted',
    description: 'Gets the total of all uncompleted appointments as of the given date.'
)]
final class AppointmentGetUncompleted extends Command
{
    public function __construct(
        private AppointmentsRepository $appointments,
        private ItemsRepository $items
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to retrieve the total of all uncompleted appointments as of a given date.')
            ->addArgument('endDate', InputArgument::REQUIRED, 'The date as of which to fetch unbilled appointments.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Uncompleted Appointments');

        $date = new \DateTime($input->getArgument('endDate'));

        $uncompletedAppointments = $this->appointments->findUncompletedAsOf($date);

        if (empty($uncompletedAppointments)) {
            $io->success('There are currently no uncompleted appointments.');

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
        $qbServices = [];

        foreach ($uncompletedAppointments as $appointment) {
            $sum = bcadd($sum, $appointment->getCharge(), 2);

            $itemId = $appointment->getPayer()->getServices()[0]->getQbItemId();
            if (!isset($qbServices[$itemId])) {
                $service = $this->items->get($itemId);
                $qbServices[$itemId] = $service->Name;
            }

            $rows[] = [
                $appointment->getServiceDate()->format('Y-m-d'),
                $qbServices[$itemId],
                $appointment->getClientName(),
                $fmt->formatCurrency((float) $appointment->getCharge(), 'USD'),
            ];
        }

        $io->text(sprintf(
            'As of %s, there were %d uncompleted appointments for a total of %s.',
            $date->format('Y-m-d'),
            \count($uncompletedAppointments),
            $fmt->formatCurrency((float) $sum, 'USD')
        ));
        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
