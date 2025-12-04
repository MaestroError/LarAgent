<?php

use LarAgent\Messages\AssistantMessage;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Messages\DataModels\MessageContent;

test('AssistantMessage: Creates with string content', function () {
    $message = new AssistantMessage('Hello');

    expect($message->getRole())->toBe('assistant');
    expect($message->getContent())->toBeInstanceOf(MessageContent::class);
    expect((string) $message->getContent())->toBe('Hello');
});

test('AssistantMessage: Creates with usage', function () {
    $message = new AssistantMessage('Hello');
    $usage = new Usage(100, 50, 150);
    $message->setUsage($usage);

    expect($message->getUsage())->toBe($usage);
    expect($message->getUsage()->promptTokens)->toBe(100);
    expect($message->getUsage()->completionTokens)->toBe(50);
    expect($message->getUsage()->totalTokens)->toBe(150);
});

test('AssistantMessage: getUsage returns null by default', function () {
    $message = new AssistantMessage('Hello');

    expect($message->getUsage())->toBeNull();
});

test('AssistantMessage: setUsage can set null', function () {
    $message = new AssistantMessage('Hello');
    $message->setUsage(new Usage(100, 50, 150));

    expect($message->getUsage())->not->toBeNull();

    $message->setUsage(null);
    expect($message->getUsage())->toBeNull();
});

test('AssistantMessage: Serializes usage in toArray()', function () {
    $message = new AssistantMessage('Hello');
    $message->setUsage(new Usage(100, 50, 150));

    $array = $message->toArray();

    expect($array['usage'])->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_tokens' => 150,
    ]);
});

test('AssistantMessage: toArray does not include usage when null', function () {
    $message = new AssistantMessage('Hello');

    $array = $message->toArray();

    expect($array)->not->toHaveKey('usage');
});

test('AssistantMessage: Reconstructs usage from fromArray()', function () {
    $data = [
        'role' => 'assistant',
        'content' => 'Hello',
        'usage' => [
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'total_tokens' => 300,
        ]
    ];

    $message = AssistantMessage::fromArray($data);

    expect($message->getUsage())->not->toBeNull();
    expect($message->getUsage()->promptTokens)->toBe(200);
    expect($message->getUsage()->completionTokens)->toBe(100);
    expect($message->getUsage()->totalTokens)->toBe(300);
});

test('AssistantMessage: fromArray handles missing usage', function () {
    $data = [
        'role' => 'assistant',
        'content' => 'Hello'
    ];

    $message = AssistantMessage::fromArray($data);

    expect($message->getUsage())->toBeNull();
});

test('AssistantMessage: Usage excluded from schema', function () {
    $message = new AssistantMessage('Hello');
    $schema = $message->toSchema();

    // Usage should be excluded from schema (not sent to LLM API)
    expect($schema['properties'])->not->toHaveKey('usage');
});

test('AssistantMessage: Round-trip preserves usage', function () {
    $original = new AssistantMessage('Test message');
    $original->setUsage(new Usage(500, 250, 750));

    // Serialize and deserialize
    $array = $original->toArray();
    $restored = AssistantMessage::fromArray($array);

    expect($restored->getUsage())->not->toBeNull();
    expect($restored->getUsage()->promptTokens)->toBe(500);
    expect($restored->getUsage()->completionTokens)->toBe(250);
    expect($restored->getUsage()->totalTokens)->toBe(750);
    expect((string) $restored->getContent())->toBe('Test message');
});

test('AssistantMessage: matchesArray returns true for no tool_calls', function () {
    $data = ['role' => 'assistant', 'content' => 'Hello'];

    expect(AssistantMessage::matchesArray($data))->toBeTrue();
});

test('AssistantMessage: matchesArray returns false for tool_calls', function () {
    $data = [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [['id' => '123', 'function' => ['name' => 'test', 'arguments' => '{}']]]
    ];

    expect(AssistantMessage::matchesArray($data))->toBeFalse();
});

test('AssistantMessage: Handles array content in fromArray', function () {
    $data = [
        'role' => 'assistant',
        'content' => [
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => 'World']
        ]
    ];

    $message = AssistantMessage::fromArray($data);

    expect((string) $message->getContent())->toBe("Hello\nWorld");
});

test('AssistantMessage: Handles single TextContent format in fromArray', function () {
    $data = [
        'role' => 'assistant',
        'content' => ['type' => 'text', 'text' => 'Single text']
    ];

    $message = AssistantMessage::fromArray($data);

    expect((string) $message->getContent())->toBe('Single text');
});

test('AssistantMessage: Preserves message_uuid in round-trip', function () {
    $original = new AssistantMessage('Hello');
    $originalId = $original->message_uuid;

    $array = $original->toArray();
    $restored = AssistantMessage::fromArray($array);

    expect($restored->message_uuid)->toBe($originalId);
});
