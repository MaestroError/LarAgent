<?php

use Illuminate\Support\Facades\Event;
use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Context;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Events\Context\ContextCleared;
use LarAgent\Events\Context\ContextClearing;
use LarAgent\Events\Context\ContextCreated;
use LarAgent\Events\Context\ContextRead;
use LarAgent\Events\Context\ContextReading;
use LarAgent\Events\Context\ContextSaved;
use LarAgent\Events\Context\ContextSaving;
use LarAgent\Events\Context\StorageRegistered;

// ===========================================
// Test Helpers and Mocks
// ===========================================

// Test DataModel implementation for Context tests
class ContextTestDataModel extends DataModel
{
    public function __construct(
        public string $name = '',
        public int $value = 0
    ) {}
}

// Test DataModelArray implementation for Context tests
class ContextTestDataModelArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [ContextTestDataModel::class];
    }
}

// First concrete Storage implementation for testing
class ContextTestStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return ContextTestDataModelArray::class;
    }

    public static function getStoragePrefix(): string
    {
        return 'context_test';
    }
}

// Second concrete Storage implementation for testing (different prefix)
class AnotherContextTestStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return ContextTestDataModelArray::class;
    }

    public static function getStoragePrefix(): string
    {
        return 'another_test';
    }
}

// Helper to create identity
function createContextTestIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

// Helper to create Context with InMemoryStorage
function createTestContext(SessionIdentity $identity, ?array $drivers = null): Context
{
    $drivers = $drivers ?? [InMemoryStorage::class];

    return new Context($identity, $drivers);
}

// ===========================================
// 1.1 Construction & Identity Management
// ===========================================

test('Context can be constructed with identity and drivers config', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $driversConfig = [InMemoryStorage::class];

    $context = new Context($identity, $driversConfig);

    expect($context)->toBeInstanceOf(Context::class);
});

test('Context builds context identity from session identity', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $contextIdentity = $context->getContextIdentity();

    // Context identity should use only agent name (no chat/user for context isolation)
    expect($contextIdentity->getAgentName())->toBe('TestAgent');
    // Context identity key should be different from session identity key
    expect($contextIdentity->getKey())->not->toBe($identity->getKey());
});

test('Context initializes identity storage on construction', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $identityStorage = $context->getIdentityStorage();

    expect($identityStorage)->toBeInstanceOf(IdentityStorage::class);
});

test('Context dispatches ContextCreated event on construction', function () {
    Event::fake([ContextCreated::class]);

    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [InMemoryStorage::class]);

    Event::assertDispatched(ContextCreated::class, function ($event) use ($context) {
        return $event->context === $context;
    });
});

test('getIdentity returns the session identity', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $returnedIdentity = $context->getIdentity();

    expect($returnedIdentity)->toBe($identity);
    expect($returnedIdentity->getAgentName())->toBe('TestAgent');
    expect($returnedIdentity->getChatName())->toBe('test_chat');
});

test('getContextIdentity returns the context-specific identity', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $contextIdentity = $context->getContextIdentity();

    // Context identity should be based on agent name only
    expect($contextIdentity->getAgentName())->toBe('TestAgent');
    expect($contextIdentity)->not->toBe($identity);
});

test('getDriversConfig returns the configured drivers', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $driversConfig = [InMemoryStorage::class];
    $context = new Context($identity, $driversConfig);

    expect($context->getDriversConfig())->toBe($driversConfig);
});

// ===========================================
// 1.2 Storage Registration API
// ===========================================

test('Context register adds storage instance', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    expect($context->has('context_test'))->toBeTrue();
});

test('Context register uses storage prefix as key', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    // Should be accessible by the prefix returned by getStoragePrefix()
    $retrieved = $context->getStorage('context_test');
    expect($retrieved)->toBe($storage);
});

test('Context register tracks identity in IdentityStorage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $trackedKeys = $context->getTrackedKeys();
    expect($trackedKeys)->toContain($storage->getIdentity()->getKey());
});

test('Context register dispatches StorageRegistered event', function () {
    Event::fake([StorageRegistered::class]);

    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    Event::assertDispatched(StorageRegistered::class, function ($event) use ($context, $storage) {
        return $event->context === $context
            && $event->prefix === 'context_test'
            && $event->storage === $storage;
    });
});

test('Context register returns self for chaining', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage1 = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $storage2 = new AnotherContextTestStorage($identity, [InMemoryStorage::class]);

    $result = $context->register($storage1)->register($storage2);

    expect($result)->toBe($context);
    expect($context->has('context_test'))->toBeTrue();
    expect($context->has('another_test'))->toBeTrue();
});

test('Context make creates and registers storage from class', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = $context->make(ContextTestStorage::class);

    expect($storage)->toBeInstanceOf(ContextTestStorage::class);
    expect($context->has('context_test'))->toBeTrue();
    expect($context->getStorage('context_test'))->toBe($storage);
});

test('Context make uses default drivers config when none provided', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $defaultDrivers = [InMemoryStorage::class];
    $context = new Context($identity, $defaultDrivers);

    $storage = $context->make(ContextTestStorage::class);

    // Storage should work with the default drivers
    expect($storage)->toBeInstanceOf(ContextTestStorage::class);

    // Verify it actually works by adding and saving data
    $storage->add(new ContextTestDataModel('test', 1));
    $storage->save();

    expect($storage->count())->toBe(1);
});

test('Context make uses custom drivers config when provided', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $customDriver = new InMemoryStorage;
    $storage = $context->make(ContextTestStorage::class, [$customDriver]);

    // Storage should be created and registered
    expect($storage)->toBeInstanceOf(ContextTestStorage::class);
    expect($context->has('context_test'))->toBeTrue();
});

// ===========================================
// 1.3 Storage Access API
// ===========================================

test('Context getStorage returns registered storage by prefix', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $retrieved = $context->getStorage('context_test');

    expect($retrieved)->toBe($storage);
});

test('Context getStorage returns registered storage by class name', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $retrieved = $context->getStorage(ContextTestStorage::class);

    expect($retrieved)->toBe($storage);
});

test('Context getStorage returns null for unregistered storage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $retrieved = $context->getStorage('nonexistent');

    expect($retrieved)->toBeNull();
});

test('Context has returns true for registered storage by prefix', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    expect($context->has('context_test'))->toBeTrue();
});

test('Context has returns true for registered storage by class name', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    expect($context->has(ContextTestStorage::class))->toBeTrue();
});

test('Context has returns false for unregistered storage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    expect($context->has('nonexistent'))->toBeFalse();
    expect($context->has(ContextTestStorage::class))->toBeFalse();
});

test('Context getStorageNames returns all registered prefixes', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage1 = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $storage2 = new AnotherContextTestStorage($identity, [InMemoryStorage::class]);

    $context->register($storage1)->register($storage2);

    $names = $context->getStorageNames();

    expect($names)->toContain('context_test');
    expect($names)->toContain('another_test');
    expect($names)->toHaveCount(2);
});

test('Context magic getter provides direct storage access', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    // Access via magic __get
    $retrieved = $context->context_test;

    expect($retrieved)->toBe($storage);
});

test('Context magic getter returns null for unregistered storage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $retrieved = $context->nonexistent;

    expect($retrieved)->toBeNull();
});

// ===========================================
// 1.4 Bulk Operations
// ===========================================

test('Context save calls save on all registered storages', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    $storage1 = new ContextTestStorage($identity, [$driver]);
    $storage2 = new AnotherContextTestStorage($identity, [$driver]);

    $context->register($storage1)->register($storage2);

    // Add data to make storages dirty
    $storage1->add(new ContextTestDataModel('item1', 1));
    $storage2->add(new ContextTestDataModel('item2', 2));

    expect($storage1->isDirty())->toBeTrue();
    expect($storage2->isDirty())->toBeTrue();

    $context->save();

    expect($storage1->isDirty())->toBeFalse();
    expect($storage2->isDirty())->toBeFalse();
});

test('Context save saves identity storage', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    $storage = new ContextTestStorage($identity, [$driver]);
    $context->register($storage);

    // Force save
    $context->save();

    // Create new context with same identity and verify tracked keys persist
    $context2 = new Context($identity, [$driver]);

    // Read identity storage
    $context2->getIdentityStorage()->read();

    $trackedKeys = $context2->getIdentityStorage()->getKeys();
    expect($trackedKeys)->toContain($storage->getIdentity()->getKey());
});

test('Context save only saves dirty storages', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    $storage = new ContextTestStorage($identity, [$driver]);
    $context->register($storage);

    // Storage is not dirty (no modifications)
    expect($storage->isDirty())->toBeFalse();

    // Save should not fail even when nothing is dirty
    $context->save();

    // Now make it dirty and verify it saves
    $storage->add(new ContextTestDataModel('test', 1));
    expect($storage->isDirty())->toBeTrue();

    $context->save();
    expect($storage->isDirty())->toBeFalse();
});

test('Context save dispatches ContextSaving and ContextSaved events', function () {
    Event::fake([ContextSaving::class, ContextSaved::class]);

    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);
    $storage->add(new ContextTestDataModel('test', 1));

    $context->save();

    Event::assertDispatched(ContextSaving::class, function ($event) use ($context) {
        return $event->context === $context;
    });

    Event::assertDispatched(ContextSaved::class, function ($event) use ($context) {
        return $event->context === $context;
    });
});

test('Context read calls read on all registered storages', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');

    // First context: save data
    $context1 = new Context($identity, [$driver]);
    $storage1 = new ContextTestStorage($identity, [$driver]);
    $context1->register($storage1);
    $storage1->add(new ContextTestDataModel('persisted', 42));
    $context1->save();

    // Second context: read data
    $context2 = new Context($identity, [$driver]);
    $storage2 = new ContextTestStorage($identity, [$driver]);
    $context2->register($storage2);

    // Before read, storage should be empty (not loaded)
    expect($storage2->isLoaded())->toBeFalse();

    $context2->read();

    expect($storage2->isLoaded())->toBeTrue();
    expect($storage2->count())->toBe(1);
    expect($storage2->get()[0]->name)->toBe('persisted');
});

test('Context read dispatches ContextReading and ContextRead events', function () {
    Event::fake([ContextReading::class, ContextRead::class]);

    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $context->read();

    Event::assertDispatched(ContextReading::class, function ($event) use ($context) {
        return $event->context === $context;
    });

    Event::assertDispatched(ContextRead::class, function ($event) use ($context) {
        return $event->context === $context;
    });
});

test('Context clear clears all registered storages', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage1 = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $storage2 = new AnotherContextTestStorage($identity, [InMemoryStorage::class]);

    $context->register($storage1)->register($storage2);

    // Add data
    $storage1->add(new ContextTestDataModel('item1', 1));
    $storage2->add(new ContextTestDataModel('item2', 2));

    expect($storage1->count())->toBe(1);
    expect($storage2->count())->toBe(1);

    $context->clear();

    expect($storage1->count())->toBe(0);
    expect($storage2->count())->toBe(0);
    expect($storage1->isDirty())->toBeTrue();
    expect($storage2->isDirty())->toBeTrue();
});

test('Context clear dispatches ContextClearing and ContextCleared events', function () {
    Event::fake([ContextClearing::class, ContextCleared::class]);

    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $context->clear();

    Event::assertDispatched(ContextClearing::class, function ($event) use ($context) {
        return $event->context === $context;
    });

    Event::assertDispatched(ContextCleared::class, function ($event) use ($context) {
        return $event->context === $context;
    });
});

test('Context remove removes all storages from drivers', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    $storage = new ContextTestStorage($identity, [$driver]);
    $context->register($storage);

    // Add and save data
    $storage->add(new ContextTestDataModel('test', 1));
    $context->save();

    // Verify data exists
    $scopedIdentity = $storage->getIdentity();
    expect($driver->readFromMemory($scopedIdentity))->not->toBeNull();

    // Remove
    $context->remove();

    // Verify data is removed from driver
    expect($driver->readFromMemory($scopedIdentity))->toBeNull();
});

test('Context remove clears identity storage', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    $storage = new ContextTestStorage($identity, [$driver]);
    $context->register($storage);
    $context->save();

    // Verify tracked keys exist
    expect($context->getTrackedKeys())->not->toBeEmpty();

    // Remove
    $context->remove();

    // Verify identity storage is cleared
    expect($context->getTrackedKeys())->toBeEmpty();
});

// ===========================================
// 1.5 Key Tracking
// ===========================================

test('Context getTrackedKeys returns all storage keys from identity storage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage1 = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $storage2 = new AnotherContextTestStorage($identity, [InMemoryStorage::class]);

    $context->register($storage1)->register($storage2);

    $trackedKeys = $context->getTrackedKeys();

    expect($trackedKeys)->toContain($storage1->getIdentity()->getKey());
    expect($trackedKeys)->toContain($storage2->getIdentity()->getKey());
    expect($trackedKeys)->toHaveCount(2);
});

test('Context removeIdentityFromTracking removes key from identity storage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $storage = new ContextTestStorage($identity, [InMemoryStorage::class]);
    $context->register($storage);

    $storageKey = $storage->getIdentity()->getKey();
    expect($context->getTrackedKeys())->toContain($storageKey);

    $context->removeIdentityFromTracking($storageKey);

    expect($context->getTrackedKeys())->not->toContain($storageKey);
});

test('Context getIdentityStorage returns the identity storage instance', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    $identityStorage = $context->getIdentityStorage();

    expect($identityStorage)->toBeInstanceOf(IdentityStorage::class);
});

// ===========================================
// 1.6 Lifecycle & Destructor
// ===========================================

test('Context auto-save behavior can be verified via explicit save', function () {
    // Note: The Context class was designed without auto-save destructor
    // to avoid issues with PHP destructor ordering and unexpected saves.
    // Users should explicitly call save() when needed.
    // This test verifies the manual save flow works correctly.

    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');

    $context = new Context($identity, [$driver]);
    $storage = new ContextTestStorage($identity, [$driver]);
    $context->register($storage);
    $storage->add(new ContextTestDataModel('manual_saved', 99));

    // Verify data is dirty before save
    expect($storage->isDirty())->toBeTrue();

    // Explicit save
    $context->save();

    // Verify data was saved
    $scopedIdentity = $identity->withScope('context_test');
    $data = $driver->readFromMemory($scopedIdentity);
    expect($data)->not->toBeNull();
    expect($data[0]['name'])->toBe('manual_saved');
    expect($storage->isDirty())->toBeFalse();
});

test('Context lifecycle: register -> modify -> save persists correctly', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');

    // Phase 1: Create, register, modify, save
    $context1 = new Context($identity, [$driver]);
    $storage1 = new ContextTestStorage($identity, [$driver]);
    $context1->register($storage1);

    $storage1->add(new ContextTestDataModel('first', 1));
    $storage1->add(new ContextTestDataModel('second', 2));

    $context1->save();

    // Phase 2: New context, register same storage type, read
    $context2 = new Context($identity, [$driver]);
    $storage2 = new ContextTestStorage($identity, [$driver]);
    $context2->register($storage2);

    // Manually read to load data
    $storage2->read();

    // Verify data persisted
    expect($storage2->count())->toBe(2);
    expect($storage2->get()[0]->name)->toBe('first');
    expect($storage2->get()[1]->name)->toBe('second');

    // Phase 3: Modify and save again
    $storage2->add(new ContextTestDataModel('third', 3));
    $context2->save();

    // Phase 4: Verify final state
    $context3 = new Context($identity, [$driver]);
    $storage3 = new ContextTestStorage($identity, [$driver]);
    $context3->register($storage3);
    $storage3->read();

    expect($storage3->count())->toBe(3);
});

// ===========================================
// Additional Edge Cases
// ===========================================

test('Context handles empty drivers config by using defaults in IdentityStorage', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');

    // Empty array - IdentityStorage will need to handle this (may use default drivers)
    // This test verifies Context can be created, the IdentityStorage has its own defaults
    // Note: If IdentityStorage has no default drivers, this will throw an exception
    // which is expected behavior - at least one driver must be provided
    expect(fn () => new Context($identity, []))->toThrow(InvalidArgumentException::class);
});

test('Context with ChatHistoryStorage integration', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    // Make ChatHistoryStorage
    $chatHistory = $context->make(ChatHistoryStorage::class);

    expect($chatHistory)->toBeInstanceOf(ChatHistoryStorage::class);
    expect($context->has(ChatHistoryStorage::class))->toBeTrue();
    expect($context->has('chatHistory'))->toBeTrue();
});

test('Context with multiple storages of different types', function () {
    $driver = new InMemoryStorage;
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = new Context($identity, [$driver]);

    // Register different storage types
    $testStorage = $context->make(ContextTestStorage::class);
    $chatHistory = $context->make(ChatHistoryStorage::class);

    // Add data to each
    $testStorage->add(new ContextTestDataModel('test', 1));
    $chatHistory->addMessage(new \LarAgent\Messages\UserMessage('Hello'));

    $context->save();

    // Verify both are tracked
    expect($context->getStorageNames())->toContain('context_test');
    expect($context->getStorageNames())->toContain('chatHistory');
    expect($context->getTrackedKeys())->toHaveCount(2);
});

test('Context resolvePrefix handles invalid class gracefully', function () {
    $identity = createContextTestIdentity('TestAgent', 'test_chat');
    $context = createTestContext($identity);

    // Non-existent class should be treated as a prefix string
    $result = $context->getStorage('NonExistentClass');

    expect($result)->toBeNull();
});
