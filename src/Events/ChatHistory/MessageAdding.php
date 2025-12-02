<?php

namespace LarAgent\Events\ChatHistory;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\ChatHistoryStorage;
use LarAgent\Core\Contracts\Message as MessageInterface;

class MessageAdding
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChatHistoryStorage $storage,
        public readonly MessageInterface $message
    ) {}
}
