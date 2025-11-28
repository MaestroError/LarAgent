<?php

use LarAgent\Message;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\DataModels\ToolResultContent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\ToolCall;

it('creates a system message', function () {
    $message = Message::system('You are a helpful assistant', ['key' => 'value']);

    expect($message)->toBeInstanceOf(SystemMessage::class)
        ->and($message->getRole())->toBe('system')
        ->and($message->getContentAsString())->toBe('You are a helpful assistant')
        ->and($message->getMetadata())->toHaveKey('key', 'value');
});

it('creates a developer message', function () {
    $message = Message::developer('This is a developer message', ['usage' => 'test']);

    expect($message)->toBeInstanceOf(DeveloperMessage::class)
        ->and($message->getRole())->toBe('developer')
        ->and($message->getContentAsString())->toBe('This is a developer message')
        ->and($message->getMetadata())->toHaveKey('usage', 'test');
});

it('creates an assistant message', function () {
    $message = Message::assistant('This is an assistant message', ['usage' => 'test']);

    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->and($message->getRole())->toBe('assistant')
        ->and($message->getContentAsString())->toBe('This is an assistant message')
        ->and($message->getMetadata())->toHaveKey('usage', 'test');
});

it('creates a user message', function () {
    $message = Message::user('This is a user message', ['timestamp' => '2025-01-01']);

    expect($message)->toBeInstanceOf(UserMessage::class)
        ->and($message->getRole())->toBe('user')
        ->and($message->getContentAsString())->toBe('This is a user message')
        ->and($message->getMetadata())->toHaveKey('timestamp', '2025-01-01');
});

it('creates a tool call message', function () {
    $toolCallId = '12345';
    $toolName = 'get_weather';
    $jsonArgs = '{"location": "Boston", "unit": "celsius"}';
    $toolCalls[] = new ToolCall($toolCallId, $toolName, $jsonArgs);
    $message = Message::toolCall($toolCalls, ['status' => 'pending']);

    expect($message)->toBeInstanceOf(ToolCallMessage::class)
        ->and($message->getToolCalls()->count())->toBe(1);
});

it('creates a tool result message', function () {
    $toolCall = new ToolCall('12345', 'get_weather', '{"location": "San Francisco, CA"}');
    $result = '{"temperature": "20°C"}';

    $message = Message::toolResult($result, $toolCall->getId(), $toolCall->getToolName(), ['status' => 'completed']);

    expect($message)->toBeInstanceOf(ToolResultMessage::class)
        ->and($message->getRole())->toBe('tool')
        ->and($message->getContentAsString())->toBe($result)
        ->and($message->getToolCallId())->toBe('12345')
        ->and($message->getToolName())->toBe('get_weather')
        ->and($message->getMetadata())->toHaveKey('status', 'completed');
});

// Edge cases

it('handles empty content for user message', function () {
    $message = Message::user('', []);

    expect($message->getContentAsString())->toBe('');
});

it('message has unique id', function () {
    $message1 = Message::user('Hello');
    $message2 = Message::user('Hello');

    expect($message1->getId())->toStartWith('msg_')
        ->and($message2->getId())->toStartWith('msg_')
        ->and($message1->getId())->not->toBe($message2->getId());
});
