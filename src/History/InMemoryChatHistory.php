<?php

namespace LarAgent\History;

use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class InMemoryChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [InMemoryStorage::class];
}
