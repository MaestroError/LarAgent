<?php

namespace LarAgent\Events\IdentityStorage;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Storages\IdentityStorage;

class IdentityAdding
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly IdentityStorage $storage,
        public readonly SessionIdentityContract $identity
    ) {}
}
