<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\AgentDTO;

class BeforeToolExecution
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentDTO $agentDto,
        public readonly ToolInterface $tool,
        public readonly ToolCallInterface $toolCall
    ) {}
}
