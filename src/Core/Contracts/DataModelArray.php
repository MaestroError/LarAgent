<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use ArrayAccess;
use IteratorAggregate;
use Countable;
use JsonSerializable;

interface DataModelArray extends DataModelContract, ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /**
     * Return the list of allowed DataModel classes.
     *
     * @return array<string>
     */
    public static function allowedModels(): array;

    /**
     * Return the discriminator field name for polymorphic arrays.
     *
     * @return string
     */
    public function discriminator(): string;

    /**
     * Add an item to the array.
     *
     * @param DataModelContract $item
     * @return static
     */
    public function add(DataModelContract $item): static;

    /**
     * Remove an item from the array.
     *
     * @param mixed $itemOrKey The item to remove (value or index) or the key to check
     * @param mixed $value The value to check if removing by key/value pair
     * @return static
     */
    public function remove(mixed $itemOrKey, mixed $value = null): static;

    /**
     * Get the first item.
     *
     * @return DataModelContract|null
     */
    public function first(): ?DataModelContract;

    /**
     * Get the last item.
     *
     * @return DataModelContract|null
     */
    public function last(): ?DataModelContract;

    /**
     * Check if the array is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Clear all items.
     *
     * @return static
     */
    public function clear(): static;

    /**
     * Filter items using a callback.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static;

    /**
     * Map items using a callback.
     *
     * @param callable $callback
     * @return array
     */
    public function map(callable $callback): array;
}
