<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appointment:get-unbilled-total',
    description: 'Gets the total of all unbilled appointments.'
)]
final class AppointmentGetUnbilledTotalCommand extends Command
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
            ->setHelp('Allows you to retrieve the total of all unbilled appointments.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Get Total of Unbilled Appointments');

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
        $qbServices = [];

        foreach ($unbilledAppointments as $appointment) {
            $sum = bcadd($sum, $appointment->getCharge(), 2);

            $itemId = $appointment->getPayer()->getServices()[0]->getQbItemId();
            if (!isset($qbServices[$itemId])) {
                $service = $this->items->get($itemId);
                $qbServices[$itemId] = $service->Name;
            }

            $rows[] = [
                $appointment->getServiceDate()->format('Y-m-d'),
                $qbServices[$itemId],
                $appointment->getUnits(),
                $fmt->formatCurrency((float) $appointment->getPayer()->getServices()[0]->getRate(), 'USD'),
                $fmt->formatCurrency((float) $appointment->getCharge(), 'USD'),
            ];
        }

        $io->text(sprintf(
            'There are %d unbilled appointments for a total of %s.',
            \count($unbilledAppointments),
            $fmt->formatCurrency((float) $sum, 'USD')
        ));
        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
