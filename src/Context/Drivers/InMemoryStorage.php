<?php

namespace LarAgent\Context\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\SessionIdentity;

class InMemoryStorage extends StorageDriver
{
    protected static array $storage = [];

    public function readFromMemory(SessionIdentity $identity): array
    {
        $key = $identity->getKey();

        return self::$storage[$key] ?? [];
    }

    public function writeToMemory(SessionIdentity $identity, array $data): void
    {
        $key = $identity->getKey();
        self::$storage[$key] = $data;
    }
}
