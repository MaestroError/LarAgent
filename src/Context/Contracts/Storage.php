<?php

namespace LarAgent\Context\Contracts;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;

interface Storage
{
    /**
     * Get all items
     *
     * @return DataModelArrayContract
     */
    public function get(): DataModelArrayContract;

    /**
     * Get the identity for this storage
     *
     * @return SessionIdentity
     */
    public function getIdentity(): SessionIdentity;

    /**
     * Set (replace) all items
     *
     * @param mixed $items
     * @return void
     */
    public function set(mixed $items): void;

    /**
     * Add an item to storage
     *
     * @param DataModelContract $item
     * @return void
     */
    public function add(DataModelContract $item): void;

    /**
     * Remove an item from storage
     *
     * @param mixed $itemOrKey The item to remove (value or index) or the key to check
     * @param mixed $value The value to check if removing by key/value pair
     * @return void
     */
    public function removeItem(mixed $itemOrKey, mixed $value = null): void;

    /**
     * Get the last item
     *
     * @return DataModelContract|null
     */
    public function getLast(): ?DataModelContract;

    /**
     * Clear all items (sets items as empty array)
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Get the count of items
     *
     * @return int
     */
    public function count(): int;

    /**
     * Save items to storage (only if changed)
     *
     * @return void
     */
    public function save(): void;

    /**
     * Read items from storage
     *
     * @return void
     */
    public function read(): void;

    /**
     * Remove storage completely from all drivers
     *
     * @return void
     */
    public function remove(): void;
}
