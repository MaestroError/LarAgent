<?php

namespace LarAgent\Context\Contracts;

interface StorageDriver
{
    /**
     * Read data from memory
     *
     * @return array|null Returns null if no data found, empty array if cleared
     */
    public function readFromMemory(SessionIdentity $identity): ?array;

    /**
     * Write data to memory
     *
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool;

    /**
     * Remove data from memory
     *
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool;

    /**
     * Create a new driver instance.
     */
    public static function make(?array $config = null): static;
}
