<?php

namespace LarAgent\History;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Context\Drivers\SessionStorage;

class SessionChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [SessionStorage::class];
}
