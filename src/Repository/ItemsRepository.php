<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Type\FilterableArray;
use QuickBooksOnline\API\Data\IPPAccount;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Facades\Item;

final class ItemsRepository
{
    use QbApiTrait;

    private \QuickBooksOnline\API\DataService\DataService $dataService;
    /**
     * @var IPPItem[]
     */
    private array $cachedItems;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
        $this->dataService = $this->qb->getDataService();
        $this->dataService->throwExceptionOnError(true);

        $this->cachedItems = [];
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

    public function get(string $itemId): IPPItem
    {
        if ($this->isItemCached($itemId)) {
            return $this->getCachedItem($itemId);
        }

        return $this->cacheItem($itemId, $this->dataService->FindById('Item', $itemId));
    }

    /**
     * @param string[] $ids
     */
    public function findAllNotIn(array $ids)
    {
        $items = $this->getDataService()->Query(
            "SELECT * FROM Item WHERE Type IN ('Service', 'NonInventory')"
        );

        return (new FilterableArray($items))->filter(
            static fn (IPPItem $item) => !\in_array($item->Id, $ids, true)
        );
    }

    private function isItemCached(string $itemId): bool
    {
        return isset($this->cachedItems[$itemId]);
    }

    private function getCachedItem(string $itemId): IPPItem
    {
        return $this->cachedItems[$itemId];
    }

    private function cacheItem(string $itemId, IPPItem $item): IPPItem
    {
        $this->cachedItems[$itemId] = $item;

        return $item;
    }
}
