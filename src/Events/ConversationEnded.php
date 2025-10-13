<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\DTO\AgentDTO;

class ConversationEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentDTO $agentDto,
        public readonly MessageInterface|array|null $message
    ) {}
}
