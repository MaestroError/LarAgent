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
     * @return array
     */
    abstract public function readFromMemory(SessionIdentity $identity): array;

    /**
     * Write data to memory
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return void
     */
    abstract public function writeToMemory(SessionIdentity $identity, array $data): void;

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
