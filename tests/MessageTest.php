<?php

use Maestroerror\LarAgent\Message;
use Maestroerror\LarAgent\Messages\AssistantMessage;
use Maestroerror\LarAgent\Messages\ToolCallMessage;
use Maestroerror\LarAgent\Messages\ToolResultMessage;
use Maestroerror\LarAgent\Messages\UserMessage;
use Maestroerror\LarAgent\Tests\Fakes\FakeLlmDriver;
use Maestroerror\LarAgent\ToolCall;

it('creates a custom message', function () {
    $message = Message::create('user', 'Custom content', ['key' => 'value']);

    expect($message->getRole())->toBe('user')
        ->and($message->getContent())->toBe('Custom content')
        ->and($message->getMetadata())->toHaveKey('key', 'value');
});

it('creates an assistant message', function () {
    $message = Message::assistant('This is an assistant message', ['usage' => 'test']);

    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->and($message->getRole())->toBe('assistant')
        ->and($message->getContent())->toBe('This is an assistant message')
        ->and($message->getMetadata())->toHaveKey('usage', 'test');
});

it('creates a user message', function () {
    $message = Message::user('This is a user message', ['timestamp' => '2025-01-01']);

    expect($message)->toBeInstanceOf(UserMessage::class)
        ->and($message->getRole())->toBe('user')
        ->and($message->getContent())->toBe('This is a user message')
        ->and($message->getMetadata())->toHaveKey('timestamp', '2025-01-01');
});

it('creates a tool call message', function () {
    $toolCallId = '12345';
    $toolName = 'get_weather';
    $jsonArgs = '{"location": "Boston", "unit": "celsius"}';
    $toolCalls[] = new ToolCall($toolCallId, $toolName, $jsonArgs);
    $driver = new FakeLlmDriver;
    $message = Message::toolCall($toolCalls, $driver->toolCallsToMessage($toolCalls), ['status' => 'pending']);

    expect($message)->toBeInstanceOf(ToolCallMessage::class)
        ->and($message->getToolCalls())->toBe($toolCalls);
});

it('creates a tool result message', function () {
    $toolCall = new ToolCall('12345', 'get_weather', '{"location": "San Francisco, CA"}');
    $result = '{"temperature": "20°C"}';
    $messageArray = (new FakeLlmDriver)->toolResultToMessage($toolCall, $result);

    $message = Message::toolResult($messageArray, ['status' => 'completed']);

    expect($message)->toBeInstanceOf(ToolResultMessage::class)
        ->and($message->getRole())->toBe('tool')
        ->and(json_decode($message->getContent()))->toHaveKey($toolCall->getToolName())
        ->and($message->getMetadata())->toHaveKey('status', 'completed');
});

// Edge cases

it('creates a custom message with invalid role', function () {
    $message = Message::create('', 'Content');

    expect($message->getRole())->toBe('');
})->throws(\InvalidArgumentException::class, 'Role cannot be empty'); // Add this validation in your class if not already there.

it('handles empty content for user message', function () {
    $message = Message::user('', []);

    expect($message->getContent())->toBe('');
});
