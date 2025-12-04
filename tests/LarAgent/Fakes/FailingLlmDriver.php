<?php

namespace LarAgent\Tests\LarAgent\Fakes;

use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;

class FailingLlmDriver extends FakeLlmDriver
{
    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = new DriverConfig): AssistantMessage|ToolCallMessage
    {
        throw new \Exception('Simulated failure');
    }

    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = new DriverConfig, ?callable $callback = null): \Generator
    {
        throw new \Exception('Simulated failure');
    }
}
