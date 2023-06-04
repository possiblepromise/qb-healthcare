<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\HcfaReader;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\ValueObject\HcfaInfo;
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

        $summary = $this->charges->getSummary($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate);

        if ($summary === null) {
            $io->error('No charges found for the given parameters.');

            return Command::INVALID;
        }

        /** @var numeric-string[] $selectedCharges */
        $selectedCharges = [];

        if (bccomp($summary->getBilledAmount(), $hcfa->total, 2) !== 0) {
            $io->caution('The total of the provided claim file does not match the total of the matched charges.');

            $io->definitionList(
                ['Payer' => $summary->getPayer()],
                ['Billed date' => $hcfa->billedDate->format('m/d/Y')],
                ['Client' => $client],
                ['From' => $hcfa->fromDate->format('m/d/Y')],
                ['To' => $hcfa->toDate->format('m/d/Y')],
                ['Total' => $fmt->formatCurrency((float) $hcfa->total, 'USD')]
            );

            $io->text('Please enter the details for the claim charges so they can be matched.');
            $io->newLine();

            $selectedCharges = $this->selectClaimCharges($client, $hcfa, $io);

            $summary = $this->charges->getSummary($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate, $selectedCharges);
            \assert($summary !== null);
        }

        $io->definitionList(
            'Claim Summary',
            ['Claim ID' => $hcfa->claimId],
            ['Date' => $hcfa->billedDate->format('m/d/Y')],
            ['Payer' => $summary->getPayer()],
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

    /**
     * @return numeric-string[]
     */
    private function selectClaimCharges(string $client, HcfaInfo $hcfa, SymfonyStyle $io): array
    {
        $charges = $this->charges->getClaimCharges($client, $hcfa->payerId, $hcfa->fromDate, $hcfa->toDate);

        $selectedCharges = [];
        $amount = '0.00';
        $chargeNum = 1;

        while (bccomp($amount, $hcfa->total, 2) !== 0) {
            $io->text("Charge {$chargeNum}:");

            $selected = self::filterByDate($charges, $io);

            if (\count($selected) === 0) {
                $io->error('No charges matched that date. Please try again.');

                continue;
            }
            if (\count($selected) > 1) {
                $selected = self::filterByBillingCode($selected, $io);

                if (\count($selected) === 0) {
                    $io->error('No charges matched that billing code. Please try again.');

                    continue;
                }
            }

            // Delete the charge so we can't select it more than once
            unset($charges[array_key_first($selected)]);
            $selectedCharge = array_shift($selected);
            $selectedCharges[] = $selectedCharge->getChargeLine();
            $amount = bcadd($amount, $selectedCharge->getBilledAmount());
            ++$chargeNum;
        }

        return $selectedCharges;
    }

    /**
     * @param Charge[] $charges
     *
     * @return Charge[]
     */
    private static function filterByDate(array $charges, SymfonyStyle $io): array
    {
        /** @var \DateTimeImmutable $date */
        $date = $io->ask('Charge date', null, self::validateDate(...));

        return array_filter(
            $charges,
            static fn (Charge $charge): bool => $charge->getServiceDate()->format('m/d/Y') === $date->format('m/d/Y')
        );
    }

    /**
     * @param Charge[] $charges
     *
     * @return Charge[]
     */
    private static function filterByBillingCode(array $charges, SymfonyStyle $io): array
    {
        $billCode = (string) $io->ask('Billing code');

        return array_filter(
            $charges,
            static fn (Charge $charge): bool => $charge->getService()->getBillingCode() === $billCode
        );
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
