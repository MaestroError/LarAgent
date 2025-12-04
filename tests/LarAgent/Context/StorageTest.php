<?php

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Abstractions\DataModelArray;

// Test DataModel implementation
class TestDataModel extends DataModel
{
    public function __construct(
        public string $name = '',
        public int $value = 0
    ) {}
}

// Test DataModelArray implementation
class TestDataModelArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [TestDataModel::class];
    }
}

// Concrete Storage implementation for testing
class TestStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return TestDataModelArray::class;
    }

    public static function getStoragePrefix(): string
    {
        return 'test';
    }
}

// Helper to create identity
function createIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

test('Storage can be constructed with drivers config', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [InMemoryStorage::class]);

    expect($storage)->toBeInstanceOf(Storage::class);
    // The storage now uses a scoped identity, so we check the original components
    expect($storage->getIdentity()->getAgentName())->toBe($identity->getAgentName());
    expect($storage->getIdentity()->getChatName())->toBe($identity->getChatName());
    expect($storage->getIdentity()->getScope())->toBe('test');
});

test('Storage get returns empty array initially', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    expect($storage->get())->toBeInstanceOf(DataModelArray::class);
    expect($storage->get()->isEmpty())->toBeTrue();
});

test('Storage set replaces all items', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    $items = [
        new TestDataModel('item1', 1),
        new TestDataModel('item2', 2),
    ];

    $storage->set($items);

    expect($storage->count())->toBe(2);
    expect($storage->get()[0]->name)->toBe('item1');
    expect($storage->isDirty())->toBeTrue();
});

test('Storage getLast returns last item', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

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
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    expect($storage->getLast())->toBeNull();
});

test('Storage clear sets items to empty array', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    $storage->set([new TestDataModel('item1', 1)]);
    expect($storage->count())->toBe(1);

    $storage->clear();

    expect($storage->get()->isEmpty())->toBeTrue();
    expect($storage->count())->toBe(0);
    expect($storage->isDirty())->toBeTrue();
});

test('Storage count returns number of items', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    expect($storage->count())->toBe(0);

    $storage->set([
        new TestDataModel('item1', 1),
        new TestDataModel('item2', 2),
        new TestDataModel('item3', 3),
    ]);

    expect($storage->count())->toBe(3);
});

test('Storage save persists items when dirty', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [$driver]);

    $storage->set([new TestDataModel('test', 42)]);
    expect($storage->isDirty())->toBeTrue();

    $storage->save();

    expect($storage->isDirty())->toBeFalse();

    // Verify data was persisted (use scoped identity)
    $scopedIdentity = $storage->getIdentity();
    $data = $driver->readFromMemory($scopedIdentity);
    expect($data)->toBe([['name' => 'test', 'value' => 42]]);
});

test('Storage save does not persist when not dirty', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [$driver]);

    expect($storage->isDirty())->toBeFalse();

    // This should do nothing
    $storage->save();

    // Driver should have no data
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('Storage read loads items from storage', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');

    // Pre-populate driver with scoped identity key
    $scopedIdentity = $identity->withScope('test');
    $driver->writeToMemory($scopedIdentity, [
        ['name' => 'loaded1', 'value' => 10],
        ['name' => 'loaded2', 'value' => 20],
    ]);

    $storage = new TestStorage($identity, [$driver]);
    $storage->read();

    $items = $storage->get();
    expect($items)->toHaveCount(2);
    expect($items[0]->name)->toBe('loaded1');
    expect($items[1]->value)->toBe(20);
});

test('Storage read handles empty storage gracefully', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');

    $storage = new TestStorage($identity, [$driver]);
    $storage->read();

    expect($storage->get()->isEmpty())->toBeTrue();
});

test('Storage isDirty tracks changes correctly', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    expect($storage->isDirty())->toBeFalse();

    $storage->set([new TestDataModel('test', 1)]);
    expect($storage->isDirty())->toBeTrue();

    $storage->save();
    expect($storage->isDirty())->toBeFalse();

    $storage->clear();
    expect($storage->isDirty())->toBeTrue();
});

test('Storage remove deletes from all drivers', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [$driver]);
    $scopedIdentity = $storage->getIdentity();

    // Save some data first
    $storage->set([new TestDataModel('test', 42)]);
    $storage->save();

    // Verify data exists
    expect($driver->readFromMemory($scopedIdentity))->toBe([['name' => 'test', 'value' => 42]]);

    // Remove
    $storage->remove();

    // Verify data is gone from driver
    expect($driver->readFromMemory($scopedIdentity))->toBeNull();
    // Verify local items are cleared
    expect($storage->get()->isEmpty())->toBeTrue();
    // Verify not dirty (already removed)
    expect($storage->isDirty())->toBeFalse();
});

test('Storage remove works with multiple drivers', function () {
    $driver1 = new InMemoryStorage;
    $driver2 = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [$driver1, $driver2]);
    $scopedIdentity = $storage->getIdentity();

    // Save some data first
    $storage->set([new TestDataModel('test', 42)]);
    $storage->save();

    // Verify data exists in both drivers
    expect($driver1->readFromMemory($scopedIdentity))->toBe([['name' => 'test', 'value' => 42]]);
    expect($driver2->readFromMemory($scopedIdentity))->toBe([['name' => 'test', 'value' => 42]]);

    // Remove
    $storage->remove();

    // Verify data is gone from both drivers
    expect($driver1->readFromMemory($scopedIdentity))->toBeNull();
    expect($driver2->readFromMemory($scopedIdentity))->toBeNull();
});

test('InMemoryStorage removeFromMemory works correctly', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');

    // Write data
    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->toBe(['test' => 'data']);

    // Remove
    $result = $driver->removeFromMemory($identity);

    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('Storage add appends item', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    $storage->add(new TestDataModel('item1', 1));

    expect($storage->count())->toBe(1);
    expect($storage->get()[0]->name)->toBe('item1');
    expect($storage->isDirty())->toBeTrue();
});

test('Storage removeItem removes item', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    $item1 = new TestDataModel('item1', 1);
    $item2 = new TestDataModel('item2', 2);

    $storage->set([$item1, $item2]);
    expect($storage->count())->toBe(2);

    $storage->removeItem($item1);

    expect($storage->count())->toBe(1);
    expect($storage->get()[0]->name)->toBe('item2');
    expect($storage->isDirty())->toBeTrue();
});

test('Storage removeItem removes item by key/value', function () {
    $identity = createIdentity('agent', 'chat');
    $storage = new TestStorage($identity, [new InMemoryStorage]);

    $item1 = new TestDataModel('item1', 1);
    $item2 = new TestDataModel('item2', 2);

    $storage->set([$item1, $item2]);
    expect($storage->count())->toBe(2);

    $storage->removeItem('name', 'item1');

    expect($storage->count())->toBe(1);
    expect($storage->get()[0]->name)->toBe('item2');
    expect($storage->isDirty())->toBeTrue();
});

// ===========================================
// SessionIdentity Scope Tests
// ===========================================

test('SessionIdentity withScope creates new identity with scope', function () {
    $identity = createIdentity('agent', 'chat');
    $scopedIdentity = $identity->withScope('chat_history');

    // Original identity unchanged
    expect($identity->getScope())->toBeNull();
    expect($identity->getKey())->toBe('agent_chat');

    // Scoped identity has scope applied
    expect($scopedIdentity->getScope())->toBe('chat_history');
    expect($scopedIdentity->getKey())->toBe('chat_history_agent_chat');

    // Other properties preserved
    expect($scopedIdentity->getAgentName())->toBe('agent');
    expect($scopedIdentity->getChatName())->toBe('chat');
});

test('SessionIdentity withScope works with group identity', function () {
    $identity = new SessionIdentity(
        agentName: 'agent',
        chatName: 'chat',
        userId: null,
        group: 'team_alpha'
    );

    $scopedIdentity = $identity->withScope('memory');

    // Key should use group instead of agent name
    expect($scopedIdentity->getKey())->toBe('memory_team_alpha_chat');
    expect($scopedIdentity->getGroup())->toBe('team_alpha');
});

test('Different storage types have isolated keys', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');

    // Create two different storage types with same identity
    $testStorage = new TestStorage($identity, [$driver]);

    // TestStorage uses 'test' prefix
    expect($testStorage->getIdentity()->getKey())->toBe('test_agent_chat');

    // Verify original identity key is different
    expect($identity->getKey())->toBe('agent_chat');
});

// Second storage type for isolation testing
class AnotherStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return TestDataModelArray::class;
    }

    public static function getStoragePrefix(): string
    {
        return 'another';
    }
}

test('Two storage types sharing same driver dont interfere', function () {
    $driver = new InMemoryStorage;
    $identity = createIdentity('agent', 'chat');

    // Create two different storage types with same identity and driver
    $storage1 = new TestStorage($identity, [$driver]);
    $storage2 = new AnotherStorage($identity, [$driver]);

    // Add different data to each
    $storage1->set([new TestDataModel('from_test', 1)]);
    $storage1->save();

    $storage2->set([new TestDataModel('from_another', 2)]);
    $storage2->save();

    // Read from each - should get their own data
    $storage1->read();
    $storage2->read();

    expect($storage1->get()[0]->name)->toBe('from_test');
    expect($storage2->get()[0]->name)->toBe('from_another');

    // Verify they have different keys
    expect($storage1->getIdentity()->getKey())->toBe('test_agent_chat');
    expect($storage2->getIdentity()->getKey())->toBe('another_agent_chat');
});

test('SessionIdentity toArray includes scope', function () {
    $identity = createIdentity('agent', 'chat');
    $scopedIdentity = $identity->withScope('state');

    $array = $scopedIdentity->toArray();

    expect($array['scope'])->toBe('state');
    expect($array['key'])->toBe('state_agent_chat');
});

test('SessionIdentity fromArray handles scope', function () {
    $data = [
        'agentName' => 'agent',
        'chatName' => 'chat',
        'scope' => 'memory',
    ];

    $identity = SessionIdentity::fromArray($data);

    expect($identity->getScope())->toBe('memory');
    expect($identity->getKey())->toBe('memory_agent_chat');
});
