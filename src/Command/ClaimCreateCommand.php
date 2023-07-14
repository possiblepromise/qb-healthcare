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
        private QuickBooks $qb
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a claim.')
            ->addArgument('837', InputArgument::OPTIONAL, 'EDI 837 file to read.')
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

        $file = $input->getArgument('837');
        if ($file === null) {
            $io->success('The next claim is from ' . $nextClaim->format('Y-m-d'));

            return Command::SUCCESS;
        }

        $edi = new Edi837Reader($file);
        $claims = $edi->process();

        try {
            foreach ($claims as $claim) {
                $this->processClaim($claim, $io);
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function processClaim(Edi837Claim $claim, SymfonyStyle $io): void
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

        $io->definitionList(
            'Claim Summary',
            ['Payer' => $summary->getPayer()],
            ['Billed date' => $summary->getBilledDate()->format('m/d/Y')],
            ['From' => $summary->getStartDate()->format('m/d/Y')],
            ['To' => $summary->getEndDate()->format('m/d/Y')],
            ['Client' => $summary->getClient()],
            ['Charge' => $fmt->formatCurrency((float) $summary->getBilledAmount(), 'USD')],
            ['Contracted' => $fmt->formatCurrency((float) $summary->getContractAmount(), 'USD')],
            ['Adjustments' => $fmt->formatCurrency((float) $summary->getContractualAdjustment(), 'USD')]
        );

        $billingId = $io->ask('Billing ID', null, $this->validateBillingId(...));

        $charges = $summary->getCharges();

        $io->section('Claim Charges');

        $table = [];

        foreach ($charges as $charge) {
            $table[] = [
                $charge->getServiceDate()->format('Y-m-d'),
                $this->items->get($charge->getService()->getQbItemId())->Name,
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
            return;
        }

        $unbilledAppointments = $this->appointments->findUnbilledFromCharges($charges);

        self::validateAppointments($charges, $unbilledAppointments);

        $this->createReversingJournalEntries($unbilledAppointments, $io);

        $this->validateDefaultPaymentTermSet($io);

        $invoice = $this->invoices->createFromCharges($billingId, $charges);

        $io->text(sprintf(
            'Created invoice %s for %s.',
            $invoice->DocNumber,
            $fmt->formatCurrency((float) $invoice->TotalAmt, 'USD')
        ));

        // Ensure we have the Contractual Adjustment item
        $this->validateContractualAdjustmentItemSet($io);

        try {
            $creditMemo = $this->creditMemos->createContractualAdjustmentCreditFromCharges(
                $billingId,
                $charges
            );

            $io->text(sprintf(
                'Created credit memo %s for %s.',
                $creditMemo->DocNumber,
                $fmt->formatCurrency((float) $creditMemo->TotalAmt, 'USD')
            ));

            $claim = $this->claims->createClaim($billingId, $invoice, $creditMemo, $charges);
        } catch (\Exception $e) {
            $this->invoices->delete($invoice);

            if (isset($creditMemo)) {
                $this->creditMemos->delete($creditMemo);
            }

            throw $e;
        }

        $io->success(sprintf('Claim %s has been processed successfully.', $claim->getBillingId()));
    }

    private function validateBillingId(string $value): string
    {
        if (preg_match('/^IN\d{8}$/', $value) === 0) {
            throw new \RuntimeException('Invalid billing ID.');
        }

        $claim = $this->claims->findOneByBillingId($value);
        if ($claim !== null) {
            throw new \RuntimeException('That billing ID has already been used.');
        }

        return $value;
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

        $io->text(sprintf(
            'Creating reversing journal entries for %s unbilled appointments...',
            \count($unbilledAppointments)
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
}
