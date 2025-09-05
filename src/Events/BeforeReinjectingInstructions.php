<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\DTO\AgentDTO;

class BeforeReinjectingInstructions
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChatHistoryInterface $chatHistory,
        public readonly AgentDTO $agentDto
    ) {}
}
