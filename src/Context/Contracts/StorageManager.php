<?php

namespace LarAgent\Context\Contracts;

interface StorageManager
{
    public function read(SessionIdentity $identity): array;

    public function save(SessionIdentity $identity, array $data): void;

    public function remove(SessionIdentity $identity): void;
}
