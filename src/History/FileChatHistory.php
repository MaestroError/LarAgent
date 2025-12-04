<?php

namespace LarAgent\History;

use LarAgent\Context\Drivers\FileStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class FileChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [FileStorage::class];
}
