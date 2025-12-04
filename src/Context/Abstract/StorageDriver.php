<?php

namespace LarAgent\Context\Abstract;

use LarAgent\Context\Contracts\StorageDriver as StorageInterface;
use LarAgent\Context\Contracts\SessionIdentity;

abstract class StorageDriver implements StorageInterface
{
    /**
     * Read data from memory
     *
     * @param SessionIdentity $identity
     * @return array|null Returns null if no data found, empty array if cleared
     */
    abstract public function readFromMemory(SessionIdentity $identity): ?array;

    /**
     * Write data to memory
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return bool True if written successfully, false if writing failed
     */
    abstract public function writeToMemory(SessionIdentity $identity, array $data): bool;

    /**
     * Remove data from memory
     *
     * @param SessionIdentity $identity
     * @return bool True if removed successfully, false if removal failed
     */
    abstract public function removeFromMemory(SessionIdentity $identity): bool;

    /**
     * Create a new driver instance.
     *
     * @param array|null $config
     * @return static
     */
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static();
        }
        return new static(...$config);
    }
}
