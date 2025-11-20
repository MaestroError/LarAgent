<?php

namespace LarAgent\Storage\Types;

use LarAgent\Core\Abstractions\Storage;
use LarAgent\Core\DTO\SessionIdentity;

class InMemoryStorage extends Storage
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
