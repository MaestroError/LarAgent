<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\DTO\AgentDTO;

class ToolChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ToolInterface $tool,
        public readonly bool $added,
        public readonly AgentDTO $agentDto
    ) {}
}