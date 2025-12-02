<?php

namespace LarAgent\Events\ChatHistory;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Messages\DataModels\MessageArray;

class ChatHistorySaving
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChatHistoryStorage $storage,
        public readonly MessageArray $messages
    ) {}
}
