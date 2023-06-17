<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Appointment;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Exception\ClaimCreationException;
use PossiblePromise\QbHealthcare\HcfaReader;
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
use PossiblePromise\QbHealthcare\ValueObject\HcfaInfo;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPTerm;
use QuickBooksOnline\API\Exception\ServiceException;
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
        private QuickBooks $qb,
        private HcfaReader $reader
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a claim.')
            ->addArgument('hcfa', InputArgument::OPTIONAL, 'HCFA file to read.')
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

        $file = $input->getArgument('hcfa');
        if ($file === null) {
            $io->success('The next claim is from ' . $nextClaim->format('Y-m-d'));

            return Command::SUCCESS;
        }

        $hcfa = $this->reader->read($file);

        if ($hcfa->billedDate->format('Y-m-d') !== $nextClaim->format('Y-m-d')) {
            $io->error(sprintf(
                'Expecting a claim billed on %s, but this claim was billed on %s.',
                $nextClaim->format('Y-m-d'),
                $hcfa->billedDate->format('Y-m-d')
            ));

            return Command::INVALID;
        }

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
                $this->items->get($charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0]->getQbItemId())->Name,
                $charge->getBilledUnits(),
                $fmt->formatCurrency((float) $charge->getService()->getRate(), 'USD'),
                $fmt->formatCurrency((float) $charge->getBilledAmount(), 'USD'),
            ];
        }

        $io->table(
            ['Date', 'Service', 'Billed Units', 'Rate', 'Total'],
            $table
        );

        $io->text('If this looks good, this claim will be imported into QuickBooks.');
        if (!$io->confirm('Continue?')) {
            return Command::SUCCESS;
        }

        $unbilledAppointments = $this->appointments->findUnbilledFromCharges($charges);

        try {
            self::validateAppointments($charges, $unbilledAppointments);
        } catch (ClaimCreationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->createReversingJournalEntries($unbilledAppointments, $io);

        $this->validateDefaultPaymentTermSet($io);

        $invoice = $this->invoices->createFromCharges($hcfa->claimId, $charges);

        // Ensure we have the Contractual Adjustment item
        $this->validateContractualAdjustmentItemSet($io);

        try {
            $creditMemo = $this->creditMemos->createContractualAdjustmentCreditFromCharges($hcfa->claimId, $charges);
        } catch (ServiceException $e) {
            $this->invoices->delete($invoice);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->claims->createClaim(
            $hcfa->claimId,
            $hcfa->fileId,
            $invoice,
            $creditMemo,
            $charges
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

    private function createReversingJournalEntries(array $unbilledAppointments, SymfonyStyle $io): void
    {
        $unbilledAppointments = (new FilterableArray($unbilledAppointments))->filter(
            static fn (Appointment $appointment) => $appointment->getQbJournalEntryId() !== null && $appointment->getQbReversingJournalEntryId() === null
        );

        if (\count($unbilledAppointments) === 0) {
            $io->text('No unbilled appointments to reverse.');
            $io->newLine();

            return;
        }

        $io->text(sprintf(
            'Creating reversing journal entries for %s unbilled appointments...',
            \count($unbilledAppointments)
        ));

        foreach ($unbilledAppointments as $appointment) {
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
}
