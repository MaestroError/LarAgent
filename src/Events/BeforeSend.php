<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\DTO\AgentDTO;

class BeforeSend
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentDTO $agentDto,
        public readonly ChatHistoryInterface $history,
        public readonly ?MessageInterface $message
    ) {}
}
