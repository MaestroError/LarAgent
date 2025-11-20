<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Core\DTO\SessionIdentity;

interface Storage
{
    /**
     * Read data from memory
     *
     * @param SessionIdentity $identity
     * @return array
     */
    public function readFromMemory(SessionIdentity $identity): array;

    /**
     * Write data to memory
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return void
     */
    public function writeToMemory(SessionIdentity $identity, array $data): void;
}
