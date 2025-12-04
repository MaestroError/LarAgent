<?php

namespace LarAgent\Events\Context;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\Contracts\Context as ContextContract;

class ContextCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ContextContract $context
    ) {}
}
