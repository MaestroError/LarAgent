<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LarAgent\Context\Drivers\EloquentStorage;
use LarAgent\Context\Models\LaragentSessionIdentity;
use LarAgent\Context\SessionIdentity;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the migration for testing
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_session_identities_table.php';
    $migration->up();
});

afterEach(function () {
    // Clean up
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_session_identities_table.php';
    $migration->down();
});

describe('EloquentStorage with SessionIdentity data', function () {

    it('can write and read session identity items', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            ['key' => 'TestAgent_chat_1', 'agent_name' => 'TestAgent', 'chat_name' => 'chat_1'],
            ['key' => 'TestAgent_chat_2', 'agent_name' => 'TestAgent', 'chat_name' => 'chat_2'],
        ];

        $result = $storage->writeToMemory($identity, $items);
        expect($result)->toBeTrue();

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toHaveCount(2);
        expect($readItems[0]['key'])->toBe('TestAgent_chat_1');
        expect($readItems[0]['agent_name'])->toBe('TestAgent');
        expect($readItems[1]['key'])->toBe('TestAgent_chat_2');
        expect($readItems[1]['chat_name'])->toBe('chat_2');
    });

    it('preserves item order', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            ['key' => 'first', 'agent_name' => 'Agent1'],
            ['key' => 'second', 'agent_name' => 'Agent2'],
            ['key' => 'third', 'agent_name' => 'Agent3'],
        ];

        $storage->writeToMemory($identity, $items);
        $readItems = $storage->readFromMemory($identity);

        expect($readItems[0]['key'])->toBe('first');
        expect($readItems[1]['key'])->toBe('second');
        expect($readItems[2]['key'])->toBe('third');
    });

    it('replaces all items on write', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        // Write initial items
        $storage->writeToMemory($identity, [
            ['key' => 'old_1', 'agent_name' => 'OldAgent'],
            ['key' => 'old_2', 'agent_name' => 'OldAgent'],
        ]);

        // Write new items
        $storage->writeToMemory($identity, [
            ['key' => 'new_1', 'agent_name' => 'NewAgent'],
        ]);

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toHaveCount(1);
        expect($readItems[0]['key'])->toBe('new_1');
    });

    it('removes all items', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $storage->writeToMemory($identity, [
            ['key' => 'test', 'agent_name' => 'TestAgent'],
        ]);

        $result = $storage->removeFromMemory($identity);
        expect($result)->toBeTrue();

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('returns null for non-existent session', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'non_existent');

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('isolates data between sessions', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity1 = new SessionIdentity('TestAgent', 'session_1');
        $identity2 = new SessionIdentity('TestAgent', 'session_2');

        $storage->writeToMemory($identity1, [
            ['key' => 'session_1_key', 'agent_name' => 'Agent1'],
        ]);

        $storage->writeToMemory($identity2, [
            ['key' => 'session_2_key', 'agent_name' => 'Agent2'],
        ]);

        $items1 = $storage->readFromMemory($identity1);
        $items2 = $storage->readFromMemory($identity2);

        expect($items1)->toHaveCount(1);
        expect($items1[0]['key'])->toBe('session_1_key');
        expect($items2)->toHaveCount(1);
        expect($items2[0]['key'])->toBe('session_2_key');
    });

    it('handles empty array', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        // Write initial data
        $storage->writeToMemory($identity, [
            ['key' => 'test', 'agent_name' => 'TestAgent'],
        ]);

        // Write empty array
        $storage->writeToMemory($identity, []);

        $readItems = $storage->readFromMemory($identity);
        expect($readItems)->toBeNull();
    });

    it('preserves all session identity fields', function () {
        $storage = new EloquentStorage(LaragentSessionIdentity::class);
        $identity = new SessionIdentity('TestAgent', 'test_chat');

        $items = [
            [
                'key' => 'full_key',
                'agent_name' => 'MyAgent',
                'chat_name' => 'my_chat',
                'user_id' => 'user_123',
                'group' => 'admin_group',
                'scope' => 'chat_history',
            ],
        ];

        $storage->writeToMemory($identity, $items);
        $readItems = $storage->readFromMemory($identity);

        expect($readItems[0]['key'])->toBe('full_key');
        expect($readItems[0]['agent_name'])->toBe('MyAgent');
        expect($readItems[0]['chat_name'])->toBe('my_chat');
        expect($readItems[0]['user_id'])->toBe('user_123');
        expect($readItems[0]['group'])->toBe('admin_group');
        expect($readItems[0]['scope'])->toBe('chat_history');
    });

});

describe('LaragentSessionIdentity model', function () {

    it('uses fill to populate fields', function () {
        $model = new LaragentSessionIdentity;
        $model->fill([
            'key' => 'test_key',
            'agent_name' => 'TestAgent',
            'chat_name' => 'test_chat',
        ]);

        expect($model->key)->toBe('test_key');
        expect($model->agent_name)->toBe('TestAgent');
        expect($model->chat_name)->toBe('test_chat');
    });

    it('casts position to integer', function () {
        $model = LaragentSessionIdentity::create([
            'session_key' => 'test_session',
            'position' => '5',
            'key' => 'test_key',
        ]);

        $model->refresh();

        expect($model->position)->toBe(5);
        expect($model->position)->toBeInt();
    });

    it('has forSession scope', function () {
        LaragentSessionIdentity::create([
            'session_key' => 'session_a',
            'position' => 0,
            'key' => 'key_a',
        ]);

        LaragentSessionIdentity::create([
            'session_key' => 'session_b',
            'position' => 0,
            'key' => 'key_b',
        ]);

        $results = LaragentSessionIdentity::forSession('session_a')->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->key)->toBe('key_a');
    });

    it('has ordered scope', function () {
        LaragentSessionIdentity::create([
            'session_key' => 'test_session',
            'position' => 2,
            'key' => 'second',
        ]);

        LaragentSessionIdentity::create([
            'session_key' => 'test_session',
            'position' => 0,
            'key' => 'first',
        ]);

        LaragentSessionIdentity::create([
            'session_key' => 'test_session',
            'position' => 1,
            'key' => 'middle',
        ]);

        $results = LaragentSessionIdentity::forSession('test_session')->ordered()->get();

        expect($results[0]->key)->toBe('first');
        expect($results[1]->key)->toBe('middle');
        expect($results[2]->key)->toBe('second');
    });

});

describe('LaragentSessionIdentity factory', function () {

    it('creates default item', function () {
        $item = LaragentSessionIdentity::factory()->create();

        expect($item->session_key)->not->toBeNull();
        expect($item->position)->toBe(0);
        expect($item->key)->not->toBeNull();
        expect($item->agent_name)->toBe('TestAgent');
    });

    it('creates item for specific session', function () {
        $item = LaragentSessionIdentity::factory()
            ->forSession('custom_session')
            ->create();

        expect($item->session_key)->toBe('custom_session');
    });

    it('creates item at specific position', function () {
        $item = LaragentSessionIdentity::factory()
            ->atPosition(5)
            ->create();

        expect($item->position)->toBe(5);
    });

    it('creates item for specific agent', function () {
        $item = LaragentSessionIdentity::factory()
            ->forAgent('CustomAgent')
            ->create();

        expect($item->agent_name)->toBe('CustomAgent');
        expect($item->key)->toContain('CustomAgent');
    });

    it('creates item for specific chat', function () {
        $item = LaragentSessionIdentity::factory()
            ->forChat('custom_chat')
            ->create();

        expect($item->chat_name)->toBe('custom_chat');
        expect($item->key)->toContain('custom_chat');
    });

    it('creates item for specific user', function () {
        $item = LaragentSessionIdentity::factory()
            ->forUser('user_456')
            ->create();

        expect($item->user_id)->toBe('user_456');
    });

    it('creates item in specific group', function () {
        $item = LaragentSessionIdentity::factory()
            ->inGroup('admin')
            ->create();

        expect($item->group)->toBe('admin');
    });

    it('creates item with specific scope', function () {
        $item = LaragentSessionIdentity::factory()
            ->withScope('memory')
            ->create();

        expect($item->scope)->toBe('memory');
        expect($item->key)->toContain('memory');
    });

});
