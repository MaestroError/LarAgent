<?php

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Abstractions\DataModel;

// Test DataModel implementation
class TestDataModel extends DataModel
{
    public function __construct(
        public string $name = '',
        public int $value = 0
    ) {}
}

// Concrete Storage implementation for testing
class TestStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return TestDataModel::class;
    }
}

// Helper to create identity
function createIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

test('Storage can be constructed with drivers config', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([InMemoryStorage::class], $identity);
    
    expect($storage)->toBeInstanceOf(Storage::class);
    expect($storage->getIdentity())->toBe($identity);
});

test('Storage get returns empty array initially', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    expect($storage->get())->toBe([]);
});

test('Storage set replaces all items', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    $items = [
        new TestDataModel('item1', 1),
        new TestDataModel('item2', 2),
    ];
    
    $storage->set($items);
    
    expect($storage->get())->toBe($items);
    expect($storage->isDirty())->toBeTrue();
});

test('Storage getLast returns last item', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    $items = [
        new TestDataModel('item1', 1),
        new TestDataModel('item2', 2),
    ];
    
    $storage->set($items);
    
    $last = $storage->getLast();
    expect($last)->toBeInstanceOf(TestDataModel::class);
    expect($last->name)->toBe('item2');
    expect($last->value)->toBe(2);
});

test('Storage getLast returns null when empty', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    expect($storage->getLast())->toBeNull();
});

test('Storage clear sets items to empty array', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    $storage->set([new TestDataModel('item1', 1)]);
    expect($storage->count())->toBe(1);
    
    $storage->clear();
    
    expect($storage->get())->toBe([]);
    expect($storage->count())->toBe(0);
    expect($storage->isDirty())->toBeTrue();
});

test('Storage count returns number of items', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    expect($storage->count())->toBe(0);
    
    $storage->set([
        new TestDataModel('item1', 1),
        new TestDataModel('item2', 2),
        new TestDataModel('item3', 3),
    ]);
    
    expect($storage->count())->toBe(3);
});

test('Storage save persists items when dirty', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([$driver], $identity);
    
    $storage->set([new TestDataModel('test', 42)]);
    expect($storage->isDirty())->toBeTrue();
    
    $storage->save();
    
    expect($storage->isDirty())->toBeFalse();
    
    // Verify data was persisted
    $data = $driver->readFromMemory($identity);
    expect($data)->toBe([['name' => 'test', 'value' => 42]]);
});

test('Storage save does not persist when not dirty', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([$driver], $identity);
    
    expect($storage->isDirty())->toBeFalse();
    
    // This should do nothing
    $storage->save();
    
    // Driver should have no data
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('Storage read loads items from storage', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    
    // Pre-populate driver
    $driver->writeToMemory($identity, [
        ['name' => 'loaded1', 'value' => 10],
        ['name' => 'loaded2', 'value' => 20],
    ]);
    
    $storage = new TestStorage([$driver], $identity);
    $storage->read();
    
    $items = $storage->get();
    expect($items)->toHaveCount(2);
    expect($items[0]->name)->toBe('loaded1');
    expect($items[1]->value)->toBe(20);
});

test('Storage read handles empty storage gracefully', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    
    $storage = new TestStorage([$driver], $identity);
    $storage->read();
    
    expect($storage->get())->toBe([]);
});

test('Storage isDirty tracks changes correctly', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([new InMemoryStorage()], $identity);
    
    expect($storage->isDirty())->toBeFalse();
    
    $storage->set([new TestDataModel('test', 1)]);
    expect($storage->isDirty())->toBeTrue();
    
    $storage->save();
    expect($storage->isDirty())->toBeFalse();
    
    $storage->clear();
    expect($storage->isDirty())->toBeTrue();
});

test('Storage remove deletes from all drivers', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([$driver], $identity);
    
    // Save some data first
    $storage->set([new TestDataModel('test', 42)]);
    $storage->save();
    
    // Verify data exists
    expect($driver->readFromMemory($identity))->toBe([['name' => 'test', 'value' => 42]]);
    
    // Remove
    $storage->remove();
    
    // Verify data is gone from driver
    expect($driver->readFromMemory($identity))->toBeNull();
    // Verify local items are cleared
    expect($storage->get())->toBe([]);
    // Verify not dirty (already removed)
    expect($storage->isDirty())->toBeFalse();
});

test('Storage remove works with multiple drivers', function () {
    $driver1 = new InMemoryStorage();
    $driver2 = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage([$driver1, $driver2], $identity);
    
    // Save some data first
    $storage->set([new TestDataModel('test', 42)]);
    $storage->save();
    
    // Verify data exists in both drivers
    expect($driver1->readFromMemory($identity))->toBe([['name' => 'test', 'value' => 42]]);
    expect($driver2->readFromMemory($identity))->toBe([['name' => 'test', 'value' => 42]]);
    
    // Remove
    $storage->remove();
    
    // Verify data is gone from both drivers
    expect($driver1->readFromMemory($identity))->toBeNull();
    expect($driver2->readFromMemory($identity))->toBeNull();
});

test('InMemoryStorage removeFromMemory works correctly', function () {
    $driver = new InMemoryStorage();
    $identity = createIdentity('agent', 'chat');
    
    // Write data
    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->toBe(['test' => 'data']);
    
    // Remove
    $result = $driver->removeFromMemory($identity);
    
    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBeNull();
});
