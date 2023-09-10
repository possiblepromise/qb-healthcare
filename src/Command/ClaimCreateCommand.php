<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Edi\Edi837Claim;
use PossiblePromise\QbHealthcare\Edi\Edi837Reader;
use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Exception\ClaimCreationException;
use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\ClaimsRepository;
use PossiblePromise\QbHealthcare\Repository\CreditMemosRepository;
use PossiblePromise\QbHealthcare\Repository\InvoicesRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use PossiblePromise\QbHealthcare\Repository\JournalEntriesRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Repository\TermsRepository;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPTerm;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'claim:create',
    description: 'Create a claim.'
)]
final class ClaimCreateCommand extends Command
{
    private const PROCESSED_CLAIMS_PATH = 'var/processed/claims';

    public function __construct(
        private ChargesRepository $charges,
        private ItemsRepository $items,
        private AppointmentsRepository $appointments,
        private JournalEntriesRepository $journalEntries,
        private ClaimsRepository $claims,
        private InvoicesRepository $invoices,
        private CreditMemosRepository $creditMemos,
        private TermsRepository $terms,
        private PayersRepository $payers,
        private QuickBooks $qb
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a claim.')
            ->addArgument('edi837', InputArgument::OPTIONAL, 'EDI 837 file to read.')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'The directory to read 837 files from')
            ->addOption('show-charges', null, InputOption::VALUE_NONE, 'Whether to show claim charges as part of the summary')
            ->addOption('copy', null, InputOption::VALUE_NONE, 'Copies the Billing ID to clipboard (works only on MacOS)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Claim');

        $nextClaim = $this->appointments->getNextClaimDate();
        if ($nextClaim === null) {
            $io->success('There are no more claims to process.');

            return Command::SUCCESS;
        }

        $file = $input->getArgument('edi837');
        $directory = $input->getOption('directory');

        if ($file !== null) {
            $edi = new Edi837Reader($file);
        } elseif ($directory !== null) {
            $edi = self::readFromDirectory($directory);

            if ($edi === null) {
                $io->error('No valid EDI 837 files were found.');

                return Command::INVALID;
            }
        } else {
            $io->success('The next claim is from ' . $nextClaim->format('Y-m-d'));

            return Command::SUCCESS;
        }

        $claims = $edi->process();
        $allClaimsProcessed = true;

        try {
            foreach ($claims as $i => $claim) {
                if ($claim->billedDate->format('Y-m-d') !== $nextClaim->format('Y-m-d')) {
                    $io->error(sprintf(
                        'The next claim date should be %s but this claim is on %s.',
                        $nextClaim->format('Y-m-d'),
                        $claim->billedDate->format('Y-m-d')
                    ));

                    return Command::INVALID;
                }

                $io->section(sprintf('Processing Claim %d of %d', $i + 1, \count($claims)));

                $processed = $this->processClaim(
                    $claim,
                    $input->getOption('show-charges'),
                    $input->getOption('copy'),
                    $io
                );

                if ($processed === false) {
                    $allClaimsProcessed = false;
                }
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($allClaimsProcessed === true) {
            self::moveProcessedClaim($file);
        }

        return Command::SUCCESS;
    }

    /**
     * @return bool true if claim has been processed, false otherwise
     *
     * @throws \Exception
     */
    private function processClaim(Edi837Claim $claim, bool $showCharges, bool $copyBillingId, SymfonyStyle $io): bool
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $summary = $this->charges->getSummary($claim);
        if ($summary === null) {
            throw new \RuntimeException('No charges found for the given parameters.');
        }

        if (bccomp($summary->getBilledAmount(), $claim->billed, 2) !== 0) {
            throw new \RuntimeException(
                'The total of the provided claim file does not match the total of the matched charges.'
            );
        }

        if ($copyBillingId) {
            self::copyBillingId($summary->getBillingId());
        }

        $io->definitionList(
            'Claim Summary',
            ['Billing ID' => $summary->getBillingId()],
            ['Payer' => $summary->getPayer()],
            ['Billed date' => $summary->getBilledDate()->format('m/d/Y')],
            ['From' => $summary->getStartDate()->format('m/d/Y')],
            ['To' => $summary->getEndDate()->format('m/d/Y')],
            ['Client' => $summary->getClient()],
            ['Charge' => $fmt->formatCurrency((float) $summary->getBilledAmount(), 'USD')],
            ['Contracted' => $fmt->formatCurrency((float) $summary->getContractAmount(), 'USD')],
            ['Adjustments' => $fmt->formatCurrency((float) $summary->getContractualAdjustment(), 'USD')]
        );

        $charges = $summary->getCharges();

        if ($showCharges === true) {
            self::showCharges($charges, $io);
        }

        $io->text('If this looks good, this claim will be imported into QuickBooks.');
        if (!$io->confirm('Continue?')) {
            return false;
        }

        $unbilledAppointments = $this->appointments->findUnbilledFromCharges($charges);

        self::validateAppointments($charges, $unbilledAppointments);

        $this->createReversingJournalEntries($unbilledAppointments, $io);

        $this->validateDefaultPaymentTermSet($io);

        $invoice = $this->invoices->createFromCharges($summary->getBillingId(), $charges);

        $io->text(sprintf(
            'Created invoice %s for %s.',
            $invoice->DocNumber,
            $fmt->formatCurrency((float) $invoice->TotalAmt, 'USD')
        ));

        // Ensure we have the Contractual Adjustment item
        $this->validateContractualAdjustmentItemSet($io);

        try {
            $creditMemo = $this->creditMemos->createContractualAdjustmentCreditFromCharges(
                $summary->getBillingId(),
                $charges
            );

            $io->text(sprintf(
                'Created credit memo %s for %s.',
                $creditMemo->DocNumber,
                $fmt->formatCurrency((float) $creditMemo->TotalAmt, 'USD')
            ));

            $claim = $this->claims->createClaim($summary->getBillingId(), $invoice, $creditMemo, $charges);
        } catch (\Exception $e) {
            $this->invoices->delete($invoice);

            if (isset($creditMemo)) {
                $this->creditMemos->delete($creditMemo);
            }

            throw $e;
        }

        $io->success(sprintf('Claim %s has been processed successfully.', $claim->getBillingId()));

        return true;
    }

    private static function readFromDirectory(string $directory): ?Edi837Reader
    {
        $files = glob("{$directory}/*.txt");
        natsort($files);

        foreach ($files as $file) {
            try {
                return new Edi837Reader($file);
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    private static function copyBillingId(string $billingId): void
    {
        $command = '/bin/echo -n ' . escapeshellarg($billingId) . ' | /usr/bin/pbcopy';
        shell_exec($command);
    }

    /**
     * @param Charge[] $charges
     */
    private static function showCharges(array $charges, SymfonyStyle $io): void
    {
        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

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
    }

    private static function validateAppointments(array $charges, array $unbilledAppointments): void
    {
        $remainingCharges = (new FilterableArray($charges))->map(
            static fn (Charge $charge) => $charge->getChargeLine()
        );

        // Perform some validation
        foreach ($unbilledAppointments as $appointment) {
            // All charges should be accounted for
            $key = array_search($appointment->getChargeId(), $remainingCharges, true);
            if ($key !== false) {
                unset($remainingCharges[$key]);
            }
        }

        if (!empty($remainingCharges)) {
            throw new ClaimCreationException(sprintf(
                'The remaining charges do not have connected appointments: %s',
                implode(', ', $remainingCharges)
            ));
        }
    }

    /**
     * @param Appointment[] $unbilledAppointments
     */
    private function createReversingJournalEntries(array $unbilledAppointments, SymfonyStyle $io): void
    {
        /** @var Appointment[] $unbilledAppointments */
        $unbilledAppointments = (new FilterableArray($unbilledAppointments))->filter(
            static fn (Appointment $appointment) => $appointment->getQbJournalEntryId() !== null && $appointment->getQbReversingJournalEntryId() === null
        );

        if (\count($unbilledAppointments) === 0) {
            $io->text('No unbilled appointments to reverse.');
            $io->newLine();

            return;
        }

        $io->text(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, ' .
            'one {Creating reversing journal entry for # unbilled appointment} ' .
            'other {Creating reversing journal entries for # unbilled appointments}' .
    '}...',
            [\count($unbilledAppointments)]
        ));

        foreach ($unbilledAppointments as $appointment) {
            $firstDay = \DateTimeImmutable::createFromMutable($appointment->getDateBilled())
                ->modify('first day of this month')
            ;

            if ($appointment->getServiceDate() >= $firstDay) {
                $entry = $this->journalEntries->deleteAccruedRevenueEntryFromAppointment($appointment);
                $this->appointments->deleteQbJournalEntry($appointment);

                $io->text(
                    sprintf(
                        'Deleted journal entry %s for appointment %s on %s',
                        $entry->DocNumber,
                        $appointment->getId(),
                        $appointment->getServiceDate()->format('Y-m-d')
                    )
                );
            } else {
                $entry = $this->journalEntries->createReversingAccruedRevenueEntryFromAppointment($appointment);
                $this->appointments->setQbReversingJournalEntry($appointment, $entry);

                $io->text(
                    sprintf(
                        'Created journal entry %s for appointment %s on %s',
                        $entry->DocNumber,
                        $appointment->getId(),
                        $appointment->getServiceDate()->format('Y-m-d')
                    )
                );
            }
        }

        $io->text('Done');
        $io->newLine();
    }

    private function validateDefaultPaymentTermSet(SymfonyStyle $io): void
    {
        if ($this->qb->getActiveCompany()->paymentTerm === null) {
            $io->text('Please select the default payment term to use.');
            $terms = new FilterableArray($this->terms->findAll());
            $name = $io->choice(
                'Default payment term',
                $terms->map(static fn (IPPTerm $term) => $term->Name)
            );
            $term = $terms->selectOne(static fn (IPPTerm $term) => $term->Name === $name);
            $this->qb->getActiveCompany()->paymentTerm = $term->Id;
            $this->qb->save();
        }
    }

    private function validateContractualAdjustmentItemSet(SymfonyStyle $io): void
    {
        if ($this->qb->getActiveCompany()->contractualAdjustmentItem === null) {
            $items = $this->items->findAllNotIn($this->payers->findAllServiceItemIds());
            $io->text('Please select the item for contractual adjustments.');
            $name = $io->choice(
                'Contractual adjustment item',
                $items->map(static fn (IPPItem $item) => $item->FullyQualifiedName)
            );
            $contractualAdjustmentItem = $items->selectOne(
                static fn (IPPItem $item) => $item->FullyQualifiedName === $name
            );
            $this->qb->getActiveCompany()->contractualAdjustmentItem = $contractualAdjustmentItem->Id;
            $this->qb->save();
        }
    }

    private static function moveProcessedClaim(string $file): void
    {
        $fileSystem = new Filesystem();

        if (!$fileSystem->exists(self::PROCESSED_CLAIMS_PATH)) {
            $fileSystem->mkdir(self::PROCESSED_CLAIMS_PATH);
        }

        $fileSystem->rename($file, self::PROCESSED_CLAIMS_PATH . '/' . basename($file));
    }
}
