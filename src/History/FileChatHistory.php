<?php

namespace LarAgent\History;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Context\Drivers\FileStorage;

class FileChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [FileStorage::class];
}
