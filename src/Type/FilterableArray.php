<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Type;

use MongoDB\Driver\Cursor;

final class FilterableArray implements \Iterator, \Countable
{
    private array $items;
    private int $position = 0;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function fromCursor(Cursor $result): self
    {
        $items = [];
        foreach ($result as $item) {
            $items[] = $item;
        }

        return new self($items);
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

public function rewind(): void
{
    $this->position = 0;
}

public function count(): int
{
    return \count($this->items);
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

    public function toArray(): array
    {
        return $this->items;
    }
}
