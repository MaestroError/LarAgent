<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LarAgent\Context\Drivers\SimpleEloquentStorage;
use LarAgent\Context\Models\LaragentStorage;
use LarAgent\Context\SessionIdentity;

uses(RefreshDatabase::class);

// Helper to create identity
function createEloquentTestIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

beforeEach(function () {
    // Run only the simple-eloquent-storage migration
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_storage_table.php';
    $migration->up();
});

afterEach(function () {
    // Clean up
    $migration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_storage_table.php';
    $migration->down();
});

test('SimpleEloquentStorage: reads null when no data exists', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('SimpleEloquentStorage: writes and reads data correctly', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('agent', 'chat');

    $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];
    $result = $driver->writeToMemory($identity, $data);

    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBe($data);
});

test('SimpleEloquentStorage: removes data correctly', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('agent', 'chat');

    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->not->toBeNull();

    $result = $driver->removeFromMemory($identity);

    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('SimpleEloquentStorage: isolates data by identity', function () {
    $driver = new SimpleEloquentStorage;
    $identity1 = createEloquentTestIdentity('agent1', 'chat1');
    $identity2 = createEloquentTestIdentity('agent2', 'chat2');

    $driver->writeToMemory($identity1, ['data' => 'one']);
    $driver->writeToMemory($identity2, ['data' => 'two']);

    expect($driver->readFromMemory($identity1))->toBe(['data' => 'one']);
    expect($driver->readFromMemory($identity2))->toBe(['data' => 'two']);
});

test('SimpleEloquentStorage: updates existing data', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('agent', 'chat');

    $driver->writeToMemory($identity, ['version' => 1]);
    expect($driver->readFromMemory($identity))->toBe(['version' => 1]);

    $driver->writeToMemory($identity, ['version' => 2, 'new_key' => 'value']);
    expect($driver->readFromMemory($identity))->toBe(['version' => 2, 'new_key' => 'value']);

    // Ensure only one record exists
    expect(LaragentStorage::count())->toBe(1);
});

test('SimpleEloquentStorage: handles complex nested data', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('agent', 'chat');

    $complexData = [
        'messages' => [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ],
        'metadata' => [
            'tokens' => 100,
            'model' => 'gpt-4',
        ],
    ];

    $driver->writeToMemory($identity, $complexData);
    expect($driver->readFromMemory($identity))->toBe($complexData);
});

test('SimpleEloquentStorage: remove returns true when record does not exist', function () {
    $driver = new SimpleEloquentStorage;
    $identity = createEloquentTestIdentity('nonexistent', 'chat');

    $result = $driver->removeFromMemory($identity);

    expect($result)->toBeTrue();
});

test('LaragentStorage model: uses correct table', function () {
    $model = new LaragentStorage;

    expect($model->getTable())->toBe('laragent_storage');
});

test('LaragentStorage model: has correct fillable attributes', function () {
    $model = new LaragentStorage;

    expect($model->getFillable())->toBe(['key', 'data']);
});

test('LaragentStorage model: casts data as array', function () {
    $model = LaragentStorage::create([
        'key' => 'test_key',
        'data' => ['foo' => 'bar'],
    ]);

    $model->refresh();

    expect($model->data)->toBeArray();
    expect($model->data)->toBe(['foo' => 'bar']);
});

test('LaragentStorage factory: creates valid model', function () {
    $storage = LaragentStorage::factory()->create();

    expect($storage)->toBeInstanceOf(LaragentStorage::class);
    expect($storage->key)->toBeString();
    expect($storage->data)->toBeArray();
});

test('LaragentStorage factory: withKey creates model with specific key', function () {
    $storage = LaragentStorage::factory()->withKey('custom_key')->create();

    expect($storage->key)->toBe('custom_key');
});

test('LaragentStorage factory: withData creates model with specific data', function () {
    $data = ['custom' => 'data'];
    $storage = LaragentStorage::factory()->withData($data)->create();

    expect($storage->data)->toBe($data);
});

test('LaragentStorage factory: empty creates model with empty data', function () {
    $storage = LaragentStorage::factory()->empty()->create();

    expect($storage->data)->toBe([]);
});

test('SimpleEloquentStorage: can use custom model', function () {
    // Create a mock/custom model class name
    $driver = new SimpleEloquentStorage(LaragentStorage::class);
    $identity = createEloquentTestIdentity('agent', 'chat');

    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->toBe(['test' => 'data']);
});
