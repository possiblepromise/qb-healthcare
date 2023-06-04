<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\HcfaReader;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'claim:create',
    description: 'Create a claim.'
)]
final class ClaimCreateCommand extends Command
{
    public function __construct(private readonly ChargesRepository $charges, private readonly PayersRepository $payers, private readonly HcfaReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a claim.')
            ->addArgument('hcfa', InputArgument::REQUIRED, 'HCFA file to read.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Claim');

        $file = (string) $input->getArgument('hcfa');
        $hcfa = $this->reader->read($file);

        $client = $this->charges->findClient($hcfa->lastName, $hcfa->firstName);
        if ($client === null) {
            $io->error('Unable to find a matching client from the given claim.');

            return Command::INVALID;
        }

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        /** @var numeric-string[] $selectedCharges */
        $selectedCharges = [];

        while (true) {
            $summary = $this->charges->getSummary($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate, $selectedCharges);

            if ($summary === null) {
                $io->error('No charges found for the given parameters.');

                return Command::INVALID;
            }

            if (bccomp($summary->getBilledAmount(), $hcfa->total, 2) === 0) {
                break;
            }

            $io->caution('The total of the provided claim file does not match the total of the matched charges.');

            $charges = $this->charges->getClaimCharges($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate);
            $choices = [];

            foreach ($charges as $charge) {
                $choices[] = $charge->getChargeLine();
            }

            $io->text('Please select the charges that should apply to this claim.');
            $io->newLine();

            /** @var numeric-string[] $selectedCharges */
            $selectedCharges = $io->choice('Charges to select', $choices, null, true);
        }

        $io->definitionList(
            'Claim Summary',
            ['Billed amount' => $fmt->formatCurrency((float) $summary->getBilledAmount(), 'USD')],
            ['Contracted amount' => $fmt->formatCurrency((float) $summary->getContractAmount(), 'USD')],
            ['Contractual adjustment' => $fmt->formatCurrency((float) $summary->getContractualAdjustment(), 'USD')],
            ['Coinsurance' => $fmt->formatCurrency((float) $summary->getCoinsurance(), 'USD')],
            ['Total discount' => $fmt->formatCurrency((float) $summary->getTotalDiscount(), 'USD')],
            ['Total' => $fmt->formatCurrency((float) $summary->getTotal(), 'USD')]
        );

        $charges = $this->charges->getClaimCharges($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate, $selectedCharges);

        $io->section('Claim Charges');

        $table = [];

        foreach ($charges as $charge) {
            $table[] = [
                $charge->getServiceDate()->format('Y-m-d'),
                $charge->getService()->getName(),
                $charge->getBilledUnits(),
                $fmt->formatCurrency((float) $charge->getService()->getRate(), 'USD'),
                $fmt->formatCurrency((float) $charge->getBilledAmount(), 'USD'),
            ];
        }

        $io->table(
            ['Date', 'Service', 'Billed Units', 'Rate', 'Total'],
            $table
        );

        $io->text('Please enter the above charges into QuickBooks.');

        $qbInvoiceNumber = (string) $io->ask('QB invoice number');

        $io->text('Now please create a credit memo with the following:');
        $io->newLine();

        $credits = [];
        $credits[] = ['Contractual Adjustment' => $fmt->formatCurrency((float) $summary->getContractualAdjustment(), 'USD')];

        if (bccomp($summary->getCoinsurance(), '0.00', 2) === 1) {
            $credits[] = ['Coinsurance' => $fmt->formatCurrency((float) $summary->getCoinsurance(), 'USD')];
            $credits[] = ['Total' => $fmt->formatCurrency((float) $summary->getTotalDiscount(), 'USD')];
        }

        $io->definitionList(...$credits);

        $qbCreditNumber = (string) $io->ask('QB credit memo number');

        $this->charges->processClaim(
            $hcfa->fileId,
            $hcfa->claimId,
            $client,
            $hcfa->payerId,
            $hcfa->fromDate,
            $hcfa->toDate,
            $qbInvoiceNumber,
            $qbCreditNumber,
            $selectedCharges
        );

        $io->success(sprintf('Claim %s has been processed successfully.', $hcfa->claimId));

        return Command::SUCCESS;
    }

    private static function validateDate(string $answer): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('m/d/Y', $answer);
        if ($date === false) {
            throw new \RuntimeException('Date must be in the format: mm/dd/yyyy.');
        }

        return $date->modify('00:00:00');
    }
}
