<?php

use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\ToolCall;
use LarAgent\Messages\DataModels\MessageArray;

it('returns an assistant message', function () {
    $driver = new FakeLlmDriver;

    $driver->addMockResponse('stop', [
        'content' => 'This is a simulated assistant response',
        'metaData' => ['usage' => ['tokens' => 10]],
    ]);

    $message = $driver->sendMessage(MessageArray::fromArray([]));

    expect($message)
        ->toBeInstanceOf(AssistantMessage::class)
        ->and($message->getContentAsString())->toBe('This is a simulated assistant response');
});

it('returns a tool call message', function () {
    $driver = new FakeLlmDriver;

    $driver->addMockResponse('tool_calls', [
        'toolName' => 'get_current_weather',
        'arguments' => '{"location": "San Francisco, CA"}',
        'metaData' => ['usage' => ['tokens' => 15]],
    ]);

    $message = $driver->sendMessage(MessageArray::fromArray([]));

    expect($message)
        ->toBeInstanceOf(ToolCallMessage::class)
        ->and($message->getToolCalls()->toArray())->toBeArray()
        ->and($message->getToolCalls()[0])->toBeInstanceOf(ToolCall::class);
});
