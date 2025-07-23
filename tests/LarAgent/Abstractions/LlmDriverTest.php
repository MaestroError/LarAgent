<?php

use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\ToolCall;

it('returns an assistant message', function () {
    $driver = new FakeLlmDriver;

    $driver->addMockResponse('stop', [
        'content' => 'This is a simulated assistant response',
        'metaData' => ['usage' => ['tokens' => 10]],
    ]);

    $message = $driver->sendMessage([]);

    expect($message)
        ->toBeInstanceOf(AssistantMessage::class)
        ->and($message->getContent())->toBe('This is a simulated assistant response');
});

it('returns a tool call message', function () {
    $driver = new FakeLlmDriver;

    $driver->addMockResponse('tool_calls', [
        'toolName' => 'get_current_weather',
        'arguments' => '{"location": "San Francisco, CA"}',
        'metaData' => ['usage' => ['tokens' => 15]],
    ]);

    $message = $driver->sendMessage([]);

    expect($message)
        ->toBeInstanceOf(ToolCallMessage::class)
        ->and($message->getToolCalls())->toBeArray()
        ->and($message->getToolCalls()[0])->toBeInstanceOf(ToolCall::class);
});
