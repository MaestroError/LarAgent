<?php

namespace LarAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Core\DTO\AgentDTO;
use Throwable;

class EngineError
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentDTO $agentDto,
        public readonly Throwable $exception
    ) {}
}
