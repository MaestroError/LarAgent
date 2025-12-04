<?php

namespace LarAgent\Events\Context;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\Contracts\Context as ContextContract;
use LarAgent\Context\Contracts\Storage as StorageContract;

class StorageRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ContextContract $context,
        public readonly string $prefix,
        public readonly StorageContract $storage
    ) {}
}
