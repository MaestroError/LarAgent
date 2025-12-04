<?php

namespace LarAgent\Context\Abstract;

use LarAgent\Context\Contracts\SessionIdentity;
use LarAgent\Context\Contracts\StorageDriver as StorageInterface;

abstract class StorageDriver implements StorageInterface
{
    /**
     * Read data from memory
     *
     * @return array|null Returns null if no data found, empty array if cleared
     */
    abstract public function readFromMemory(SessionIdentity $identity): ?array;

    /**
     * Write data to memory
     *
     * @return bool True if written successfully, false if writing failed
     */
    abstract public function writeToMemory(SessionIdentity $identity, array $data): bool;

    /**
     * Remove data from memory
     *
     * @return bool True if removed successfully, false if removal failed
     */
    abstract public function removeFromMemory(SessionIdentity $identity): bool;

    /**
     * Create a new driver instance.
     */
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static;
        }

        return new static(...$config);
    }
}
