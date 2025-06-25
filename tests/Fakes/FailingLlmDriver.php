<?php

namespace LarAgent\Tests\Fakes;

use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;

class FailingLlmDriver extends FakeLlmDriver
{
    public function sendMessage(array $messages, array $options = []): AssistantMessage|ToolCallMessage
    {
        throw new \Exception('Simulated failure');
    }

    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        throw new \Exception('Simulated failure');
    }
}
