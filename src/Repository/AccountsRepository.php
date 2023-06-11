<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPAccount;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Facades\Item;

final class AccountsRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @return IPPAccount[]
     */
    public function findAllServiceIncomeAccounts(): array
    {
        return $this->getDataService()->Query(
            "SELECT * FROM Account WHERE AccountSubType = 'ServiceFeeIncome'"
        );
    }

    public function findByCategory(IPPItem $category)
    {
        /** @var IPPItem[]|null $items */
        $items = $this->getDataService()->Query(
            "SELECT * FROM Item WHERE Type = 'Service' AND ParentRef = '{$category->Id}'"
        );

        if ($items === null) {
            return [];
        }

        return $items;
    }

    public function createCategory(string $name): IPPItem
    {
        $category = Item::create([
            'Type' => 'Category',
            'Name' => $name,
        ]);

        return $this->getDataService()->add($category);
    }
}
