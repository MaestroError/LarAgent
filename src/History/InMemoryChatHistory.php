<?php

namespace LarAgent\History;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

class InMemoryChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
   public function __construct(
        array|string $driversConfig,
        SessionIdentityContract $identity,
        bool $storeMeta = false
    ) {
        parent::__construct(\LarAgent\Context\Drivers\InMemoryStorage::class, $identity);
        $this->storeMeta = $storeMeta;
    }
}
