<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\Entity\Service;
use PossiblePromise\QbHealthcare\Repository\AccountsRepository;
use PossiblePromise\QbHealthcare\Repository\CustomersRepository;
use PossiblePromise\QbHealthcare\Repository\ItemsRepository;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Serializer\PayerSerializer;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPAccount;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'import:services',
    description: 'Imports supported payers and services.'
)]
final class ImportServicesCommand extends Command
{
    public function __construct(private PayerSerializer $serializer, private PayersRepository $payers, private CustomersRepository $customers, private ItemsRepository $items, private AccountsRepository $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to import a list of supported payers and services.')
            ->addArgument('file', InputArgument::REQUIRED, 'The CSV file to import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Import Services');

        $file = $input->getArgument('file');
        Assert::string($file);
        if (!file_exists($file)) {
            $io->error('Given file does not exist.');

            return Command::INVALID;
        }

        $payers = $this->serializer->unserialize($file);
        $customers = new FilterableArray($this->customers->findAll());
        $categories = new FilterableArray($this->items->findAllCategories());

        foreach ($payers as $payer) {
            $this->processPayer($payer, $customers, $categories, $io);
        }

        $this->payers->import($payers);

        $io->success(sprintf('Imported %d payers', \count($payers)));

        return Command::SUCCESS;
    }

    private function processPayer(Payer $payer, FilterableArray $customers, FilterableArray $categories, SymfonyStyle $io): void
    {
        $io->text('Processing ' . $payer->getName() . '...');

        if ($payer->getQbCustomerId() === null) {
            $customer = $this->attachCustomer($customers, $payer, $io);
        } else {
            $customer = $this->customers->findById($payer->getQbCustomerId());
        }

        if ($payer->getQbCategoryId() === null) {
            $category = $this->attachCategory($categories, $payer, $customer, $io);
        }

        $items = new FilterableArray($this->items->findByCategory($category));
        $io->newLine();
        $io->text('Importing services...');

        foreach ($payer->getServices() as $service) {
            $this->processService($service, $category, $items, $io);
        }

        $io->newLine();
    }

    private function attachCustomer(FilterableArray $customers, Payer $payer, SymfonyStyle $io): IPPCustomer
    {
        $io->text("Let's match this payer to a QB customer.");

        if ($customers->isEmpty()) {
            // No options left so must create new one
            $io->text('No matching customer found. Please create a new one below.');
            $customer = $this->createCustomer($payer, $io);

            return $customer;
        }

        /** @var IPPCustomer $customer */
        $customer = $customers->findMatch(
            static fn (IPPCustomer $customer): bool => strtolower($customer->DisplayName) === strtolower($payer->getName())
        );

        if ($customer) {
            $io->text("Found customer {$customer->DisplayName}.");
            $payer->setQbCustomerId($customer->Id);

            return $customer;
        }

        $names = $customers->map(static fn (IPPCustomer $customer): string => $customer->DisplayName);
        $names[] = 'Create New';

        $name = $io->choice(
            'Select the QB customer to attach to this payer',
            $names
        );

        if ($name === 'Create New') {
            $customer = $this->createCustomer($payer, $io);

            return $customer;
        }

        /** @var IPPCustomer $customer */
        $customer = $customers->selectOne(
            static fn (IPPCustomer $customer): bool => $customer->DisplayName === $name
        );
        $payer->setQbCustomerId($customer->Id);

        $io->text("Found customer {$customer->DisplayName}.");

        return $customer;
    }

    private function createCustomer(Payer $payer, SymfonyStyle $io): IPPCustomer
    {
        $name = $io->ask('Display name', $payer->getName());

        $customer = $this->customers->createFromPayer($name, $payer);
        $payer->setQbCustomerId($customer->Id);
        $io->text("The customer {$customer->DisplayName} has been created.");

        return $customer;
    }

    private function attachCategory(FilterableArray $categories, Payer $payer, IPPCustomer $customer, SymfonyStyle $io): IPPItem
    {
        $io->newLine();
        $io->text("Now let's match the payer to an item category.");
        if ($categories->isEmpty()) {
            $io->text("No matching category found. Creating a category called {$customer->DisplayName}.");
            $category = $this->items->createCategory($customer->DisplayName);
            $io->text("The category {$category->Name} has been created.");
            $payer->setQbCategoryId($category->Id);

            return $category;
        }

        /** @var IPPItem $category */
        $category = $categories->findMatch(
            static fn (IPPItem $category): bool => strtolower($category->Name) === strtolower($payer->getName())
                || $category->Name === $payer->getName()
        );

        if ($category) {
            $io->text("Found category {$category->Name}");
            $payer->setQbCategoryId($category->Id);

            return $category;
        }

        $names = $categories->map(static fn (IPPItem $category): string => $category->Name);
        $names[] = 'Create New';

        $name = $io->choice(
            'Select the QB category to attach to this payer',
            $names
        );

        if ($name === 'Create New') {
            $category = $this->items->createCategory($customer->DisplayName);
            $io->text("The category {$category->Name} has been created.");
            $payer->setQbCategoryId($category->Id);

            return $category;
        }

        /** @var IPPItem $category */
        $category = $categories->selectOne(
            static fn (IPPItem $category) => $category->Name === $name
        );
        $payer->setQbCategoryId($category->Id);

        return $category;
    }

    private function processService(Service $service, IPPItem $category, FilterableArray $items, SymfonyStyle $io): void
    {
        $io->text('Processing ' . $service->getName() . '...');

        if ($service->getQbItemId() === null) {
            $this->attachItem($items, $category, $service, $io);
        }
    }

    private function attachItem(FilterableArray $items, IPPItem $category, Service $service, SymfonyStyle $io)
    {
        if ($items->isEmpty()) {
            $item = $this->createItem($category, $service, $io);

            return $item;
        }

        $item = $items->findMatch(
            static fn (IPPItem $item): bool => strtolower($item->Name) === strtolower($service->getName())
        );

        if ($item) {
            $io->text("Found item {$item->Name}.");
            $service->setQbItemId($item->Id);

            return $item;
        }

        $names = $items->map(static fn (IPPItem $item): string => $item->Name);
        $names[] = 'Create New';

        $name = $io->choice('Select the QB item to attach to this service', $names);

        if ($name === 'Create New') {
            $item = $this->createItem($category, $service, $io);

            return $item;
        }

        $item = $items->selectOne(
            static fn (IPPItem $item): bool => $item->Name === $name
        );
        $service->setQbItemId($item->Id);

        return $item;
    }

    private function createItem(IPPItem $category, Service $service, SymfonyStyle $io): IPPItem
    {
        $io->text('No matching item found. Please create one below.');
        $name = $io->ask('Item name', $service->getName());
        $accounts = new FilterableArray($this->accounts->findAllServiceIncomeAccounts());
        $accountName = $io->choice(
            'Income account',
            $accounts->map(static fn (IPPAccount $account): string => $account->FullyQualifiedName)
        );

        /** @var IPPAccount $account */
        $account = $accounts->selectOne(
            static fn (IPPAccount $account) => $account->FullyQualifiedName === $accountName
        );

        $item = $this->items->createServiceItem($name, $category, $service->getRate(), $account);
        $io->text("The item {$item->FullyQualifiedName} has been created.");
        $io->newLine();

        $service->setQbItemId($item->Id);

        return $item;
    }
}
