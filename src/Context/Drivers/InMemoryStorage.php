<?php

namespace LarAgent\Context\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class InMemoryStorage extends StorageDriver
{
    protected array $storage = [];

    public function readFromMemory(SessionIdentity $identity): array
    {
        $key = $identity->getKey();

        return $this->storage[$key] ?? [];
    }

    public function writeToMemory(SessionIdentity $identity, array $data): void
    {
        $key = $identity->getKey();
        $this->storage[$key] = $data;
    }
}
