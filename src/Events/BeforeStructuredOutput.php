<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\DTO\AgentDTO;

class BeforeStructuredOutput
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $response,
        public readonly AgentDTO $agentDto
    ) {}
}
