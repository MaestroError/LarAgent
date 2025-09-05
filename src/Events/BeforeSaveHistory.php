<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\DTO\AgentDTO;

class BeforeSaveHistory
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChatHistoryInterface $history,
        public readonly AgentDTO $agentDto
    ) {}
}
