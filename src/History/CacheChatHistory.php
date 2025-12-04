<?php

namespace LarAgent\History;

use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class CacheChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [CacheStorage::class];
}
