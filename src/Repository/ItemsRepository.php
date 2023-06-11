<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPAccount;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Facades\Item;

final class ItemsRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    public function findAllCategories(): array
    {
        return $this->getDataService()->Query("SELECT * FROM Item WHERE Type = 'Category'");
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

    public function createServiceItem(string $name, IPPItem $category, string $unitPrice, IPPAccount $incomeAccount): IPPItem
    {
        $item = Item::create([
            'Name' => $name,
            'IncomeAccountRef' => [
                'value' => $incomeAccount->Id,
            ],
            'Type' => 'Service',
            'SubItem' => true,
            'ParentRef' => [
                'value' => $category->Id,
            ],
            'UnitPrice' => $unitPrice,
        ]);

        return $this->getDataService()->add($item);
    }
}
