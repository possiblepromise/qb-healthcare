<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\ValueObject\ClientRevenue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'client:revenue',
    description: 'Generates a report of revenue per client.'
)]
final class ClientRevenueCommand extends Command
{
    public function __construct(private AppointmentsRepository $appointments)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Generates a report of the revenue earned per client.')
            ->addOption('startMonth', null, InputOption::VALUE_REQUIRED, 'The month to start the report')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'File to export the report to')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Client Revenue');

        $endDate = new \DateTimeImmutable('last day of last month midnight');
        $startDate = $endDate;
        if ($input->getOption('startMonth')) {
            $startDate = (new \DateTimeImmutable($input->getOption('startMonth')))
                ->modify('last day of this month midnight')
            ;

            if ($startDate > $endDate) {
                $io->error(sprintf('Start month cannot be later than %s.', $endDate->format('F, Y')));

                return Command::INVALID;
            }
        }

        $months = [];

        for ($date = $startDate; $date <= $endDate; $date = $date->modify('last day of next month midnight')) {
            $month = $this->appointments->getClientRevenue($date);

            if (!empty($month)) {
                $months[$date->format('M Y')] = $month;
            }
        }

        if (empty($months)) {
            $io->success('There are currently no clients to display.');

            return Command::SUCCESS;
        }

        if (\count($months) === 1) {
            $headers = ['Client', 'Revenue'];
        } else {
            $headers = ['Client', ...array_keys($months)];
        }

        $rows = [];
        $totals = array_fill_keys(array_keys($months), '0.00');
        $counts = array_fill_keys(array_keys($months), 0);
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        /** @var ClientRevenue[] $clients */
        foreach ($months as $month => $clients) {
            foreach ($clients as $client) {
                if (!isset($rows[$client->client])) {
                    // Initialize the row for this client
                    $rows[$client->client] = [$client->client, ...array_fill(0, \count($months), '')];
                }

                $totals[$month] = bcadd($totals[$month], $client->revenue, 2);
                ++$counts[$month];

                $rows[$client->client][array_search($month, array_keys($months), true) + 1] = $fmt->formatCurrency((float) $client->revenue, 'USD');
            }
        }

        $rows = array_values($rows);

        // Sort by client
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $rows[] = [
            'Average',
            ...array_map(
                static fn (string $total, int $count) => $fmt->formatCurrency((float) bcdiv($total, (string) $count, 2), 'USD'),
                $totals,
                $counts
            ),
        ];

        $rows[] = [
            'Total',
            ...array_map(
                static fn (string $total): string => $fmt->formatCurrency((float) $total, 'USD'),
                $totals
            ),
        ];

        $io->table(
            $headers,
            $rows
        );

        if ($input->getOption('export')) {
            self::saveToFile($headers, $rows, $input);
        }

        return Command::SUCCESS;
    }

    private static function saveToFile(array $headers, array $rows, InputInterface $input): void
    {
        $handle = fopen($input->getOption('export'), 'w');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
