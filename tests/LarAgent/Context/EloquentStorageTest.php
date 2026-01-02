<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LarAgent\Context\Drivers\EloquentStorage;
use LarAgent\Context\Models\LaragentMessage;
use LarAgent\Context\SessionIdentity;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the migration for testing
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_messages_table.php';
    $migration->up();
});

afterEach(function () {
    // Clean up
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_messages_table.php';
    $migration->down();
});

describe('EloquentStorage', function () {

    it('can write and read items', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            ['role' => 'user', 'content' => 'Hello', 'message_uuid' => 'msg_123'],
            ['role' => 'assistant', 'content' => 'Hi there!', 'message_uuid' => 'msg_456'],
        ];

        $result = $storage->writeToMemory($identity, $items);
        expect($result)->toBeTrue();

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toHaveCount(2);
        expect($readItems[0]['role'])->toBe('user');
        expect($readItems[0]['content'])->toBe('Hello');
        expect($readItems[1]['role'])->toBe('assistant');
        expect($readItems[1]['content'])->toBe('Hi there!');
    });

    it('preserves item order', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            ['role' => 'system', 'content' => 'First'],
            ['role' => 'user', 'content' => 'Second'],
            ['role' => 'assistant', 'content' => 'Third'],
        ];

        $storage->writeToMemory($identity, $items);
        $readItems = $storage->readFromMemory($identity);

        expect($readItems[0]['content'])->toBe('First');
        expect($readItems[1]['content'])->toBe('Second');
        expect($readItems[2]['content'])->toBe('Third');
    });

    it('replaces all items on write', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        // Write initial items
        $storage->writeToMemory($identity, [
            ['role' => 'user', 'content' => 'Old message 1'],
            ['role' => 'user', 'content' => 'Old message 2'],
        ]);

        // Write new items
        $storage->writeToMemory($identity, [
            ['role' => 'assistant', 'content' => 'New message'],
        ]);

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toHaveCount(1);
        expect($readItems[0]['content'])->toBe('New message');
    });

    it('removes all items', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $storage->writeToMemory($identity, [
            ['role' => 'user', 'content' => 'Test'],
        ]);

        $result = $storage->removeFromMemory($identity);
        expect($result)->toBeTrue();

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('returns null for non-existent session', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'non_existent');

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('isolates data between sessions', function () {
        $storage = new EloquentStorage;
        $identity1 = new SessionIdentity('TestAgent', 'chat_1');
        $identity2 = new SessionIdentity('TestAgent', 'chat_2');

        $storage->writeToMemory($identity1, [
            ['role' => 'user', 'content' => 'Session 1'],
        ]);

        $storage->writeToMemory($identity2, [
            ['role' => 'user', 'content' => 'Session 2'],
        ]);

        $items1 = $storage->readFromMemory($identity1);
        $items2 = $storage->readFromMemory($identity2);

        expect($items1)->toHaveCount(1);
        expect($items1[0]['content'])->toBe('Session 1');
        expect($items2)->toHaveCount(1);
        expect($items2[0]['content'])->toBe('Session 2');
    });

    it('handles empty array', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        // Write initial data
        $storage->writeToMemory($identity, [
            ['role' => 'user', 'content' => 'Test'],
        ]);

        // Write empty array
        $storage->writeToMemory($identity, []);

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('preserves all message fields', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            [
                'role' => 'assistant',
                'content' => 'Hello',
                'message_uuid' => 'msg_abc123',
                'message_created' => '2025-12-03T10:00:00+00:00',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'metadata' => ['source' => 'test'],
                'extras' => ['custom' => 'value'],
            ],
        ];

        $storage->writeToMemory($identity, $items);
        $readItems = $storage->readFromMemory($identity);

        expect($readItems[0]['role'])->toBe('assistant');
        expect($readItems[0]['content'])->toBe('Hello');
        expect($readItems[0]['message_uuid'])->toBe('msg_abc123');
        expect($readItems[0]['message_created'])->toBe('2025-12-03T10:00:00+00:00');
        expect($readItems[0]['usage'])->toBe(['prompt_tokens' => 10, 'completion_tokens' => 5]);
        expect($readItems[0]['metadata'])->toBe(['source' => 'test']);
        expect($readItems[0]['extras'])->toBe(['custom' => 'value']);
    });

    it('preserves tool call fields', function () {
        $storage = new EloquentStorage;
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $toolCalls = [
            [
                'id' => 'call_123',
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Boston"}'],
            ],
        ];

        $items = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $toolCalls,
            ],
            [
                'role' => 'tool',
                'content' => '{"temperature": "72°F"}',
                'tool_call_id' => 'call_123',
            ],
        ];

        $storage->writeToMemory($identity, $items);
        $readItems = $storage->readFromMemory($identity);

        expect($readItems[0]['tool_calls'])->toBe($toolCalls);
        expect($readItems[1]['tool_call_id'])->toBe('call_123');
    });

});

describe('LaragentMessage model', function () {

    it('uses fill to populate fields', function () {
        $model = new LaragentMessage;
        $model->fill([
            'role' => 'user',
            'content' => 'Test content',
            'message_uuid' => 'msg_test',
        ]);

        expect($model->role)->toBe('user');
        expect($model->content)->toBe('Test content');
        expect($model->message_uuid)->toBe('msg_test');
    });

    it('casts JSON fields correctly', function () {
        $model = LaragentMessage::create([
            'session_key' => 'test_key',
            'position' => 0,
            'role' => 'assistant',
            'tool_calls' => [['id' => 'call_1']],
            'usage' => ['tokens' => 100],
            'metadata' => ['key' => 'value'],
        ]);

        $model->refresh();

        expect($model->tool_calls)->toBe([['id' => 'call_1']]);
        expect($model->usage)->toBe(['tokens' => 100]);
        expect($model->metadata)->toBe(['key' => 'value']);
    });

});

describe('LaragentMessage factory', function () {

    it('creates default item', function () {
        $item = LaragentMessage::factory()->create();

        expect($item->session_key)->not->toBeNull();
        expect($item->role)->toBe('user');
        expect($item->content)->not->toBeNull();
        expect($item->message_uuid)->toStartWith('msg_');
    });

    it('creates user message', function () {
        $item = LaragentMessage::factory()->userMessage('Hello world')->create();

        expect($item->role)->toBe('user');
        expect($item->content)->toBe('Hello world');
    });

    it('creates assistant message', function () {
        $item = LaragentMessage::factory()->assistantMessage('Hi there')->create();

        expect($item->role)->toBe('assistant');
        expect($item->content)->toBe('Hi there');
    });

    it('creates tool call message', function () {
        $item = LaragentMessage::factory()->toolCallMessage()->create();

        expect($item->role)->toBe('assistant');
        expect($item->content)->toBeNull();
        expect($item->tool_calls)->not->toBeNull();
    });

    it('creates tool result message', function () {
        $item = LaragentMessage::factory()->toolResultMessage('call_123', '{"result": true}')->create();

        expect($item->role)->toBe('tool');
        expect($item->tool_call_id)->toBe('call_123');
        expect($item->content)->toBe('{"result": true}');
    });

    it('adds usage statistics', function () {
        $item = LaragentMessage::factory()->withUsage()->create();

        expect($item->usage)->toHaveKey('prompt_tokens');
        expect($item->usage)->toHaveKey('completion_tokens');
    });

});
