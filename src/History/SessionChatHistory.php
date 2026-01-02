<?php

namespace LarAgent\History;

use LarAgent\Context\Drivers\SessionStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class SessionChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [SessionStorage::class];
}
