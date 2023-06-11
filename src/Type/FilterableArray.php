<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Type;

final class FilterableArray
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback)));
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    public function remove(mixed $item): void
    {
        $key = array_search($item, $this->items, true);

        if ($key !== false) {
            unset($this->items[$key]);
        }
    }

    public function map(callable $callback): array
    {
        return array_values(array_map($callback, $this->items));
    }

    public function findMatch(callable $searchCallback)
    {
        $filteredItems = $this->filter($searchCallback);

        if (!$filteredItems->isEmpty()) {
            $item = $filteredItems->shift();
            $this->remove($item);

            return $item;
        }

        return null;
    }

    public function selectOne(callable $callback, bool $remove = true)
    {
        $items = $this->filter($callback);
        if ($items->isEmpty()) {
            return null;
        }

        $item = $items->shift();

        if ($remove === true) {
            $this->remove($item);
        }

        return $item;
    }
}
