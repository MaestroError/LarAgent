<?php

namespace LarAgent\Context\Contracts;

use LarAgent\Core\Contracts\DataModel;

interface Storage
{
    /**
     * Get all items
     *
     * @return DataModel[]
     */
    public function get(): array;

    /**
     * Get the identity for this storage
     *
     * @return SessionIdentity
     */
    public function getIdentity(): SessionIdentity;

    /**
     * Set (replace) all items
     *
     * @param DataModel[] $items
     * @return void
     */
    public function set(array $items): void;

    /**
     * Get the last item
     *
     * @return DataModel|null
     */
    public function getLast(): ?DataModel;

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
