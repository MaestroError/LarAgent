<?php

namespace LarAgent\Core\Contracts;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LarAgent\Core\Contracts\DataModel as DataModelContract;

interface DataModelArray extends ArrayAccess, Countable, DataModelContract, IteratorAggregate, JsonSerializable
{
    /**
     * Return the list of allowed DataModel classes.
     *
     * @return array<string>
     */
    public static function allowedModels(): array;

    /**
     * Return the discriminator field name for polymorphic arrays.
     */
    public function discriminator(): string;

    /**
     * Add an item to the array.
     */
    public function add(DataModelContract $item): static;

    /**
     * Remove an item from the array.
     *
     * @param  mixed  $itemOrKey  The item to remove (value or index) or the key to check
     * @param  mixed  $value  The value to check if removing by key/value pair
     */
    public function remove(mixed $itemOrKey, mixed $value = null): static;

    /**
     * Get the first item.
     */
    public function first(): ?DataModelContract;

    /**
     * Get the last item.
     */
    public function last(): ?DataModelContract;

    /**
     * Check if the array is empty.
     */
    public function isEmpty(): bool;

    /**
     * Clear all items.
     */
    public function clear(): static;

    /**
     * Filter items using a callback.
     */
    public function filter(callable $callback): static;

    /**
     * Map items using a callback.
     */
    public function map(callable $callback): array;
}
