<?php

namespace LarAgent\History;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Context\Drivers\CacheStorage;

class CacheChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [CacheStorage::class];
}
