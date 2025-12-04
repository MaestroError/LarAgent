<?php

namespace LarAgent\Context\Contracts;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;

interface Storage
{
    /**
     * Get all items
     */
    public function get(): DataModelArrayContract;

    /**
     * Get the identity for this storage
     */
    public function getIdentity(): SessionIdentity;

    /**
     * Set (replace) all items
     */
    public function set(mixed $items): void;

    /**
     * Add an item to storage
     */
    public function add(DataModelContract $item): void;

    /**
     * Remove an item from storage
     *
     * @param  mixed  $itemOrKey  The item to remove (value or index) or the key to check
     * @param  mixed  $value  The value to check if removing by key/value pair
     */
    public function removeItem(mixed $itemOrKey, mixed $value = null): void;

    /**
     * Get the last item
     */
    public function getLast(): ?DataModelContract;

    /**
     * Clear all items (sets items as empty array)
     */
    public function clear(): void;

    /**
     * Get the count of items
     */
    public function count(): int;

    /**
     * Save items to storage (only if changed)
     */
    public function save(): void;

    /**
     * Read items from storage
     */
    public function read(): void;

    /**
     * Remove storage completely from all drivers
     */
    public function remove(): void;
}
