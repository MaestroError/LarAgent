<?php

namespace LarAgent\Events\IdentityStorage;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Storages\IdentityStorage;

class IdentityStorageSaving
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly IdentityStorage $storage,
        public readonly SessionIdentityArray $identities
    ) {}
}
