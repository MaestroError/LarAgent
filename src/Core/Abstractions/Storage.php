<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\Storage as StorageInterface;
use LarAgent\Core\DTO\SessionIdentity;

abstract class Storage implements StorageInterface
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
}
