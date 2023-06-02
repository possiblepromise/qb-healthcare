<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payment:create',
    description: 'Create a payment.'
)]
final class PaymentCreateCommand extends Command
{
    public function __construct(private readonly ChargesRepository $charges, private readonly PayersRepository $payers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to create a payment.')
            ->addArgument('paymentRef', InputArgument::REQUIRED, 'The payment reference')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $paymentRef = (string) $input->getArgument('paymentRef');

        $io->title('Create Payment');

        $charges = $this->charges->findByPaymentRef($paymentRef);

        if (empty($charges)) {
            $io->error('No charges match that payment reference.');

            return Command::INVALID;
        }

        $billed = '0.00';
        $contract = '0.00';
        $coinsurance = '0.00';
        $qbInvoices = [];
        $qbCreditMemos = [];

        foreach ($charges as $charge) {
            $billed = bcadd($billed, $charge->getBilledAmount(), 2);
            $contract = bcadd($contract, $charge->getContractAmount() ?? '0.00', 2);
            $coinsurance = bcadd($coinsurance, $charge->getPrimaryPaymentInfo()->getCoinsurance() ?? '0.00', 2);
            $qbInvoices[] = $charge->getQbInvoiceNumber() ?? '';
            $qbCreditMemos[] = $charge->getQbCreditMemoNumber() ?? '';
        }

        $qbInvoices = array_unique($qbInvoices);
        $qbCreditMemos = array_unique($qbCreditMemos);

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $io->text(sprintf('Found %s charges for this payment.', \count($charges)));
        $io->newLine();

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

        $total = bcsub($contract, $coinsurance, 2);

        $io->definitionList(
            ['Billed amount' => $fmt->formatCurrency((float) $billed, 'USD')],
            ['Contract amount' => $fmt->formatCurrency((float) $contract, 'USD')],
            ['Coinsurance' => $fmt->formatCurrency((float) $coinsurance, 'USD')],
            ['Total' => $fmt->formatCurrency((float) $total, 'USD')]
        );

        do {
            $io->text(sprintf(
                'Please enter a payment into QB for %s, as follows:',
                $fmt->formatCurrency((float) $total, 'USD')
            ));
            $io->newLine();

            $list = [];

            foreach ($qbInvoices as $invoice) {
                $list[] = ["Invoice {$invoice}" => $fmt->formatCurrency((float) $this->charges->getInvoiceTotal($invoice), 'USD')];
            }

            foreach ($qbCreditMemos as $creditMemo) {
                $list[] = ["Credit memo {$creditMemo}" => $fmt->formatCurrency((float) $this->charges->getCreditMemoTotal($creditMemo), 'USD')];
            }

            $io->definitionList(...$list);

            $io->text(sprintf('Enter reference number: %s', $paymentRef));
        } while (!$io->confirm('Have you created the payment?'));

        $this->charges->createPayment($paymentRef);

        $io->success(sprintf('Payment %s has been processed successfully.', $paymentRef));

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
