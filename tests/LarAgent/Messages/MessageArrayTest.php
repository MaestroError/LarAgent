<?php

use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\ToolCall;

test('MessageArray: Reconstructs UserMessage from array', function () {
    $data = [
        ['role' => 'user', 'content' => 'Hello']
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(1);
    expect($messageArray[0])->toBeInstanceOf(UserMessage::class);
    expect((string) $messageArray[0]->getContent())->toBe('Hello');
});

test('MessageArray: Reconstructs SystemMessage from array', function () {
    $data = [
        ['role' => 'system', 'content' => 'You are a helpful assistant']
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(1);
    expect($messageArray[0])->toBeInstanceOf(SystemMessage::class);
    expect((string) $messageArray[0]->getContent())->toBe('You are a helpful assistant');
});

test('MessageArray: Reconstructs AssistantMessage from array', function () {
    $data = [
        ['role' => 'assistant', 'content' => 'Hello! How can I help you?']
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(1);
    expect($messageArray[0])->toBeInstanceOf(AssistantMessage::class);
    expect((string) $messageArray[0]->getContent())->toBe('Hello! How can I help you?');
});

test('MessageArray: Reconstructs ToolCallMessage from array', function () {
    $data = [
        [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'arguments' => '{"location": "London"}'
                    ]
                ]
            ]
        ]
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(1);
    expect($messageArray[0])->toBeInstanceOf(ToolCallMessage::class);
    expect($messageArray[0]->getToolCalls())->toHaveCount(1);
});

test('MessageArray: Distinguishes AssistantMessage from ToolCallMessage', function () {
    $data = [
        ['role' => 'assistant', 'content' => 'Regular response'],
        [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_456',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{}']
                ]
            ]
        ]
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(2);
    expect($messageArray[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messageArray[0])->not->toBeInstanceOf(ToolCallMessage::class);
    expect($messageArray[1])->toBeInstanceOf(ToolCallMessage::class);
});

test('MessageArray: Reconstructs ToolResultMessage from array', function () {
    $data = [
        [
            'role' => 'tool',
            'content' => '{"temperature": 20}',
            'tool_call_id' => 'call_123'
        ]
    ];

    $messageArray = MessageArray::fromArray($data);

    expect($messageArray)->toHaveCount(1);
    expect($messageArray[0])->toBeInstanceOf(ToolResultMessage::class);
});

test('MessageArray: Mixed message types round-trip correctly', function () {
    $data = [
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
        [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_789',
                    'type' => 'function',
                    'function' => ['name' => 'calculate', 'arguments' => '{"x": 5}']
                ]
            ]
        ],
        [
            'role' => 'tool',
            'content' => '10',
            'tool_call_id' => 'call_789'
        ],
        ['role' => 'assistant', 'content' => 'The result is 10']
    ];

    $messageArray = MessageArray::fromArray($data);
    $serialized = $messageArray->toArray();

    expect($messageArray)->toHaveCount(6);
    expect($messageArray[0])->toBeInstanceOf(SystemMessage::class);
    expect($messageArray[1])->toBeInstanceOf(UserMessage::class);
    expect($messageArray[2])->toBeInstanceOf(AssistantMessage::class);
    expect($messageArray[3])->toBeInstanceOf(ToolCallMessage::class);
    expect($messageArray[4])->toBeInstanceOf(ToolResultMessage::class);
    expect($messageArray[5])->toBeInstanceOf(AssistantMessage::class);
});

test('MessageArray: AssistantMessage with usage round-trips correctly', function () {
    $message = new AssistantMessage('Test response');
    $message->setUsage(new Usage(100, 50, 150));

    $messageArray = new MessageArray([$message]);
    $serialized = $messageArray->toArray();

    expect($serialized[0]['usage'])->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_tokens' => 150,
    ]);

    // Round-trip
    $restored = MessageArray::fromArray($serialized);

    expect($restored[0])->toBeInstanceOf(AssistantMessage::class);
    expect($restored[0]->getUsage())->not->toBeNull();
    expect($restored[0]->getUsage()->promptTokens)->toBe(100);
    expect($restored[0]->getUsage()->completionTokens)->toBe(50);
    expect($restored[0]->getUsage()->totalTokens)->toBe(150);
});

test('MessageArray: Can add message objects directly', function () {
    $messageArray = new MessageArray();

    $messageArray->add(new UserMessage('Hello'));
    $messageArray->add(new AssistantMessage('Hi!'));

    expect($messageArray)->toHaveCount(2);
    expect($messageArray[0])->toBeInstanceOf(UserMessage::class);
    expect($messageArray[1])->toBeInstanceOf(AssistantMessage::class);
});

test('MessageArray: toArray preserves message order', function () {
    $messages = [
        new SystemMessage('System'),
        new UserMessage('User 1'),
        new AssistantMessage('Assistant 1'),
        new UserMessage('User 2'),
        new AssistantMessage('Assistant 2'),
    ];

    $messageArray = new MessageArray($messages);
    $array = $messageArray->toArray();

    expect($array[0]['role'])->toBe('system');
    expect($array[1]['role'])->toBe('user');
    expect($array[2]['role'])->toBe('assistant');
    expect($array[3]['role'])->toBe('user');
    expect($array[4]['role'])->toBe('assistant');
});

test('MessageArray: Handles empty array', function () {
    $messageArray = new MessageArray([]);

    expect($messageArray)->toHaveCount(0);
    expect($messageArray->isEmpty())->toBeTrue();
    expect($messageArray->toArray())->toBe([]);
});

test('MessageArray: first() and last() methods work', function () {
    $messages = [
        new UserMessage('First'),
        new AssistantMessage('Middle'),
        new UserMessage('Last'),
    ];

    $messageArray = new MessageArray($messages);

    expect((string) $messageArray->first()->getContent())->toBe('First');
    expect((string) $messageArray->last()->getContent())->toBe('Last');
});

test('MessageArray: clear() empties the array', function () {
    $messages = [
        new UserMessage('Hello'),
        new AssistantMessage('Hi'),
    ];

    $messageArray = new MessageArray($messages);
    expect($messageArray)->toHaveCount(2);

    $messageArray->clear();
    expect($messageArray)->toHaveCount(0);
    expect($messageArray->isEmpty())->toBeTrue();
});
