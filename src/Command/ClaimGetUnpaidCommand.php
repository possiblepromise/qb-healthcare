<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\ClaimsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'claim:get-unpaid',
    description: 'Gets the total of all unpaid claims.'
)]
final class ClaimGetUnpaidCommand extends Command
{
    public function __construct(private ClaimsRepository $claims)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to retrieve the total of all unpaid claims.')
            ->addArgument('endDate', InputArgument::OPTIONAL, 'The date up to which to fetch unpaid claims')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Get Unpaid Claims');

        $endDate = null;
        if ($input->getArgument('endDate') !== null) {
            $endDate = new \DateTimeImmutable($input->getArgument('endDate'));
        }

        $unpaidClaims = $this->claims->findUnpaid($endDate);

        if (empty($unpaidClaims)) {
            $io->success('All claims have been paid.');

            return Command::SUCCESS;
        }

        $headers = [
            'Billed Date',
            'Dates',
            'Client',
            'Billed',
            'Contracted',
        ];

        $rows = [];
        $billedTotal = '0.00';
        $contractedTotal = '0.00';
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        foreach ($unpaidClaims as $claim) {
            $billedTotal = bcadd($billedTotal, $claim->getBilledAmount(), 2);
            $contractedTotal = bcadd($contractedTotal, $claim->getContractAmount(), 2);

            $rows[] = [
                $claim->getPaymentInfo()->getBilledDate()->format('Y-m-d'),
                $claim->getStartDate()->format('n/j') . '-' . $claim->getEndDate()->format('n/j'),
                $claim->getClientName(),
                $fmt->formatCurrency((float) $claim->getBilledAmount(), 'USD'),
                $fmt->formatCurrency((float) $claim->getContractAmount(), 'USD'),
            ];
        }

        $rows[] = [
            'Total',
            '',
            '',
            $fmt->formatCurrency((float) $billedTotal, 'USD'),
            $fmt->formatCurrency((float) $contractedTotal, 'USD'),
        ];

        if ($endDate === null) {
            $io->text(\MessageFormatter::formatMessage(
                'en_US',
                '{0, plural, ' .
                'one {There is # unpaid claim} ' .
                'other {There are # unpaid claims}' .
        '}.',
                [\count($unpaidClaims), $billedTotal]
            ));
        } else {
            $io->text(\MessageFormatter::formatMessage(
                'en_US',
                'As of {0, date,yyyy-MM-dd}, there were {1, plural, ' .
                'one {# unpaid claim} ' .
                'other {# unpaid claims}' .
                '}.',
                [$endDate, \count($unpaidClaims), $billedTotal]
            ));
        }

        $io->newLine();

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
