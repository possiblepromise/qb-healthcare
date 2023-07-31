<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\Service;
use PossiblePromise\QbHealthcare\Repository\AppointmentsRepository;
use PossiblePromise\QbHealthcare\Repository\ChargesRepository;
use PossiblePromise\QbHealthcare\Repository\ClaimsRepository;
use PossiblePromise\QbHealthcare\Repository\CreditMemosRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'service:change-contract-rate',
    description: 'Changes the contract rate of a service.'
)]
final class ServiceChangeContractRateCommand extends Command
{
    public function __construct(
        private PayersRepository $payers,
        private AppointmentsRepository $appointments,
        private ChargesRepository $charges,
        private ClaimsRepository $claims,
        private CreditMemosRepository $creditMemos
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to change the contract rate of a service.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Change Contract Rate');

        $payers = new FilterableArray($this->payers->findAll());

        $payerName = $io->choice(
            'Payer',
            $payers->map(static fn (Payer $payer): string => $payer->getName())
        );

        /** @var Payer $payer */
        $payer = $payers->selectOne(static fn (Payer $payer): bool => $payer->getName() === $payerName);

        $services = new FilterableArray($payer->getServices());

        $serviceName = $io->choice(
            'Service',
            $services->map(static fn (Service $service): string => $service->getName())
        );

        /** @var Service $service */
        $service = $services->selectOne(static fn (Service $service): bool => $service->getName() === $serviceName);

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);

        $io->definitionList(
            ['Payer' => $payer->getName()],
            ['Service' => $service->getName()],
            ['Rate' => $fmt->formatCurrency((float) $service->getRate(), 'USD')],
            ['Current contract rate' => $fmt->formatCurrency((float) $service->getContractRate(), 'USD')]
        );

        /** @var string $newContractRate */
        $newContractRate = $io->ask('New contract rate', null, static function (string $value): string {
            if (!is_numeric($value)) {
                throw new \RuntimeException('Value must be a valid number.');
            }

            return sprintf('%.2f', $value);
        });

        $service->setContractRate($newContractRate);

        $this->payers->save($payer);

        $io->success('Updated payer ' . $payer->getId());

        $appointments = $this->appointments->findUnpaidFromPayerAndService($payer, $service);

        foreach ($appointments as $appointment) {
            $appointment->getPayer()->getServices()[0]->setContractRate($newContractRate);
            $this->appointments->save($appointment);
        }

        $io->success(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, one {Updated # appointment} other {Updated # appointments}}',
            [\count($appointments)]
        ));

        $charges = $this->charges->findUnpaidFromPayerAndService($payer, $service);

        foreach ($charges as $charge) {
            $charge->getPrimaryPaymentInfo()->getPayer()->getServices()[0]->setContractRate($newContractRate);
            $charge->getService()->setContractRate($newContractRate);
            $charge->setContractAmount(
                bcmul($newContractRate, (string) $charge->getBilledUnits(), 2)
            );
            $this->charges->save($charge);
        }

        $io->success(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, one {Updated # charge} other {Updated # charges}}',
            [\count($charges)]
        ));

        $claims = $this->claims->findByCharges($charges);
        $updateCount = 0;

        foreach ($claims as $claim) {
            foreach ($claim->getQbCreditMemoIds() as $creditMemoId) {
                $claimCharges = array_filter(
                    $charges,
                    static fn (Charge $charge): bool => \in_array($charge->getChargeLine(), $claim->getCharges(), true)
                );

                $creditMemo = $this->creditMemos->updateContractRate($creditMemoId, $newContractRate, $claimCharges);
                $io->text('Updated credit memo ' . $creditMemo->DocNumber);
                ++$updateCount;
            }
        }

        $io->success(\MessageFormatter::formatMessage(
            'en_US',
            '{0, plural, one {Updated # credit memo} other {Updated # credit memos}}',
            [$updateCount]
        ));

        return Command::SUCCESS;
    }
}
