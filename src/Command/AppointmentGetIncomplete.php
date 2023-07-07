<?php

/** @noinspection PhpUnused */

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
    name: 'appointment:get-incomplete',
    description: 'Gets the total of all incomplete appointments as of the given date.'
)]
final class AppointmentGetIncomplete extends Command
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
            ->setHelp('Allows you to retrieve the total of all incomplete appointments as of a given date.')
            ->addArgument('endDate', InputArgument::REQUIRED, 'The date as of which to fetch unbilled appointments.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Incomplete Appointments');

        $date = new \DateTime($input->getArgument('endDate'));

        $incompleteAppointments = $this->appointments->findIncompleteAsOf($date);

        if (empty($incompleteAppointments)) {
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

        foreach ($incompleteAppointments as $appointment) {
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
            'As of %s, there were %d incomplete appointments for a total of %s.',
            $date->format('Y-m-d'),
            \count($incompleteAppointments),
            $fmt->formatCurrency((float) $sum, 'USD')
        ));
        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
