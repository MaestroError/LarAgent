<?php

namespace LarAgent\Events\IdentityStorage;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Context\DataModels\SessionIdentityArray;

class IdentityStorageLoaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly IdentityStorage $storage,
        public readonly SessionIdentityArray $identities
    ) {}
}
