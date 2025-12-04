<?php

use Illuminate\Support\Facades\Event;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Events\IdentityStorage\IdentityAdding;
use LarAgent\Events\IdentityStorage\IdentityAdded;
use LarAgent\Events\IdentityStorage\IdentityStorageSaving;
use LarAgent\Events\IdentityStorage\IdentityStorageSaved;
use LarAgent\Events\IdentityStorage\IdentityStorageLoaded;

// ===========================================
// Test Helpers
// ===========================================

// Helper to create a base identity for IdentityStorage
function createIdentityStorageTestIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

// Helper to create IdentityStorage with InMemoryStorage driver
function createTestIdentityStorage(SessionIdentity $identity, ?array $drivers = null): IdentityStorage
{
    $drivers = $drivers ?? [InMemoryStorage::class];
    return new IdentityStorage($identity, $drivers);
}

// ===========================================
// 2.1 Basic Operations
// ===========================================

test('IdentityStorage can be constructed', function () {
    $identity = createIdentityStorageTestIdentity('TestAgent', 'test_chat');
    $storage = createTestIdentityStorage($identity);
    
    expect($storage)->toBeInstanceOf(IdentityStorage::class);
});

test('IdentityStorage getStoragePrefix returns context', function () {
    expect(IdentityStorage::getStoragePrefix())->toBe('context');
});

test('IdentityStorage uses SessionIdentityArray as data model', function () {
    $identity = createIdentityStorageTestIdentity('TestAgent', 'test_chat');
    $storage = createTestIdentityStorage($identity);
    
    $items = $storage->getIdentities();
    
    expect($items)->toBeInstanceOf(SessionIdentityArray::class);
});

// ===========================================
// 2.2 Identity Management
// ===========================================

test('IdentityStorage addIdentity adds new identity', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    expect($storage->count())->toBe(1);
    expect($storage->hasKey($trackedIdentity->getKey()))->toBeTrue();
});

test('IdentityStorage addIdentity does not duplicate existing keys', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    
    // Add same identity twice
    $storage->addIdentity($trackedIdentity);
    $storage->addIdentity($trackedIdentity);
    
    // Should only have one entry
    expect($storage->count())->toBe(1);
});

test('IdentityStorage addIdentity marks storage as dirty', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    expect($storage->isDirty())->toBeFalse();
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    expect($storage->isDirty())->toBeTrue();
});

test('IdentityStorage addIdentity dispatches IdentityAdding and IdentityAdded events', function () {
    Event::fake([IdentityAdding::class, IdentityAdded::class]);
    
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    Event::assertDispatched(IdentityAdding::class, function ($event) use ($storage, $trackedIdentity) {
        return $event->storage === $storage
            && $event->identity->getKey() === $trackedIdentity->getKey();
    });
    
    Event::assertDispatched(IdentityAdded::class, function ($event) use ($storage, $trackedIdentity) {
        return $event->storage === $storage
            && $event->identity->getKey() === $trackedIdentity->getKey();
    });
});

test('IdentityStorage removeByKey removes identity by key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity1 = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $trackedIdentity2 = createIdentityStorageTestIdentity('TestAgent', 'chat_2');
    
    $storage->addIdentity($trackedIdentity1);
    $storage->addIdentity($trackedIdentity2);
    
    expect($storage->count())->toBe(2);
    
    $storage->removeByKey($trackedIdentity1->getKey());
    
    expect($storage->count())->toBe(1);
    expect($storage->hasKey($trackedIdentity1->getKey()))->toBeFalse();
    expect($storage->hasKey($trackedIdentity2->getKey()))->toBeTrue();
});

test('IdentityStorage removeByKey marks storage as dirty', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    $storage->save(); // Reset dirty flag
    
    expect($storage->isDirty())->toBeFalse();
    
    $storage->removeByKey($trackedIdentity->getKey());
    
    expect($storage->isDirty())->toBeTrue();
});

test('IdentityStorage removeByKey does nothing for non-existent key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    $storage->save(); // Reset dirty flag
    
    expect($storage->isDirty())->toBeFalse();
    
    // Try to remove non-existent key
    $storage->removeByKey('nonexistent_key');
    
    // Should not be dirty since nothing changed
    expect($storage->isDirty())->toBeFalse();
    expect($storage->count())->toBe(1);
});

test('IdentityStorage hasKey returns true for tracked key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    expect($storage->hasKey($trackedIdentity->getKey()))->toBeTrue();
});

test('IdentityStorage hasKey returns false for untracked key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    expect($storage->hasKey('nonexistent_key'))->toBeFalse();
});

test('IdentityStorage getByKey returns identity for tracked key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    $retrieved = $storage->getByKey($trackedIdentity->getKey());
    
    expect($retrieved)->not->toBeNull();
    expect($retrieved->getKey())->toBe($trackedIdentity->getKey());
    expect($retrieved->getAgentName())->toBe('TestAgent');
    expect($retrieved->getChatName())->toBe('chat_1');
});

test('IdentityStorage getByKey returns null for untracked key', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $retrieved = $storage->getByKey('nonexistent_key');
    
    expect($retrieved)->toBeNull();
});

test('IdentityStorage getKeys returns all tracked keys', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity1 = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $trackedIdentity2 = createIdentityStorageTestIdentity('TestAgent', 'chat_2');
    $trackedIdentity3 = createIdentityStorageTestIdentity('AnotherAgent', 'chat_3');
    
    $storage->addIdentity($trackedIdentity1);
    $storage->addIdentity($trackedIdentity2);
    $storage->addIdentity($trackedIdentity3);
    
    $keys = $storage->getKeys();
    
    expect($keys)->toBeArray();
    expect($keys)->toHaveCount(3);
    expect($keys)->toContain($trackedIdentity1->getKey());
    expect($keys)->toContain($trackedIdentity2->getKey());
    expect($keys)->toContain($trackedIdentity3->getKey());
});

test('IdentityStorage getIdentities returns SessionIdentityArray', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    $identities = $storage->getIdentities();
    
    expect($identities)->toBeInstanceOf(SessionIdentityArray::class);
    expect($identities)->toHaveCount(1);
    expect($identities[0]->getKey())->toBe($trackedIdentity->getKey());
});

// ===========================================
// 2.3 Persistence
// ===========================================

test('IdentityStorage save dispatches events', function () {
    Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
    
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    $storage->save();
    
    Event::assertDispatched(IdentityStorageSaving::class, function ($event) use ($storage) {
        return $event->storage === $storage;
    });
    
    Event::assertDispatched(IdentityStorageSaved::class, function ($event) use ($storage) {
        return $event->storage === $storage;
    });
});

test('IdentityStorage save only saves when dirty', function () {
    Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
    
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    // Not dirty - save should not dispatch events
    $storage->save();
    
    Event::assertNotDispatched(IdentityStorageSaving::class);
    Event::assertNotDispatched(IdentityStorageSaved::class);
    
    // Make dirty
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage->addIdentity($trackedIdentity);
    
    // Now save should dispatch events
    $storage->save();
    
    Event::assertDispatched(IdentityStorageSaving::class);
    Event::assertDispatched(IdentityStorageSaved::class);
});

test('IdentityStorage load dispatches IdentityStorageLoaded event', function () {
    Event::fake([IdentityStorageLoaded::class]);
    
    $driver = new InMemoryStorage();
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    
    // First, create and save some data
    $storage1 = new IdentityStorage($baseIdentity, [$driver]);
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $storage1->addIdentity($trackedIdentity);
    $storage1->save();
    
    Event::fake([IdentityStorageLoaded::class]); // Reset event fake
    
    // Create new storage and load
    $storage2 = new IdentityStorage($baseIdentity, [$driver]);
    $storage2->read(); // Triggers load
    
    Event::assertDispatched(IdentityStorageLoaded::class, function ($event) use ($storage2) {
        return $event->storage === $storage2;
    });
});

test('IdentityStorage persists identities across instances', function () {
    $driver = new InMemoryStorage();
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    
    // First instance: add identities and save
    $storage1 = new IdentityStorage($baseIdentity, [$driver]);
    $trackedIdentity1 = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $trackedIdentity2 = createIdentityStorageTestIdentity('TestAgent', 'chat_2');
    
    $storage1->addIdentity($trackedIdentity1);
    $storage1->addIdentity($trackedIdentity2);
    $storage1->save();
    
    // Second instance: read and verify
    $storage2 = new IdentityStorage($baseIdentity, [$driver]);
    $storage2->read();
    
    expect($storage2->count())->toBe(2);
    expect($storage2->hasKey($trackedIdentity1->getKey()))->toBeTrue();
    expect($storage2->hasKey($trackedIdentity2->getKey()))->toBeTrue();
    
    // Verify identity data is preserved
    $retrieved = $storage2->getByKey($trackedIdentity1->getKey());
    expect($retrieved->getAgentName())->toBe('TestAgent');
    expect($retrieved->getChatName())->toBe('chat_1');
});

// ===========================================
// Additional Edge Cases
// ===========================================

test('IdentityStorage uses context scope for key isolation', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent', 'test_chat');
    $storage = createTestIdentityStorage($baseIdentity);
    
    // The storage identity should have 'context' scope
    $storageIdentity = $storage->getIdentity();
    expect($storageIdentity->getScope())->toBe('context');
});

test('IdentityStorage can track identities with different scopes', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    // Create identities with different scopes (like different storage types would have)
    $chatHistoryIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1')->withScope('chatHistory');
    $stateIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1')->withScope('state');
    
    $storage->addIdentity($chatHistoryIdentity);
    $storage->addIdentity($stateIdentity);
    
    expect($storage->count())->toBe(2);
    expect($storage->hasKey($chatHistoryIdentity->getKey()))->toBeTrue();
    expect($storage->hasKey($stateIdentity->getKey()))->toBeTrue();
    
    // Keys should be different due to different scopes
    expect($chatHistoryIdentity->getKey())->not->toBe($stateIdentity->getKey());
});

test('IdentityStorage handles empty state correctly', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    expect($storage->count())->toBe(0);
    expect($storage->getKeys())->toBe([]);
    expect($storage->getIdentities()->isEmpty())->toBeTrue();
});

test('IdentityStorage clear removes all tracked identities', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity1 = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    $trackedIdentity2 = createIdentityStorageTestIdentity('TestAgent', 'chat_2');
    
    $storage->addIdentity($trackedIdentity1);
    $storage->addIdentity($trackedIdentity2);
    
    expect($storage->count())->toBe(2);
    
    $storage->clear();
    
    expect($storage->count())->toBe(0);
    expect($storage->isDirty())->toBeTrue();
});

test('IdentityStorage getKeys returns empty array when no identities', function () {
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $keys = $storage->getKeys();
    
    expect($keys)->toBeArray();
    expect($keys)->toBeEmpty();
});

test('IdentityStorage addIdentity IdentityAdding fires always but IdentityAdded only when actually added', function () {
    Event::fake([IdentityAdding::class, IdentityAdded::class]);
    
    $baseIdentity = createIdentityStorageTestIdentity('TestAgent');
    $storage = createTestIdentityStorage($baseIdentity);
    
    $trackedIdentity = createIdentityStorageTestIdentity('TestAgent', 'chat_1');
    
    // Add same identity twice
    $storage->addIdentity($trackedIdentity);
    $storage->addIdentity($trackedIdentity);
    
    // IdentityAdding should fire both times (always fires on addIdentity call)
    Event::assertDispatchedTimes(IdentityAdding::class, 2);
    // IdentityAdded should only fire once (only when identity is actually added)
    Event::assertDispatchedTimes(IdentityAdded::class, 1);
});

test('IdentityStorage multiple agents can have separate identity storages', function () {
    $driver = new InMemoryStorage();
    
    // Agent 1
    $agent1Identity = createIdentityStorageTestIdentity('Agent1');
    $storage1 = new IdentityStorage($agent1Identity, [$driver]);
    $storage1->addIdentity(createIdentityStorageTestIdentity('Agent1', 'chat_1'));
    $storage1->save();
    
    // Agent 2
    $agent2Identity = createIdentityStorageTestIdentity('Agent2');
    $storage2 = new IdentityStorage($agent2Identity, [$driver]);
    $storage2->addIdentity(createIdentityStorageTestIdentity('Agent2', 'chat_2'));
    $storage2->save();
    
    // Reload and verify isolation
    $reloaded1 = new IdentityStorage($agent1Identity, [$driver]);
    $reloaded1->read();
    
    $reloaded2 = new IdentityStorage($agent2Identity, [$driver]);
    $reloaded2->read();
    
    expect($reloaded1->count())->toBe(1);
    expect($reloaded2->count())->toBe(1);
    
    // Each should only have their own identity
    $keys1 = $reloaded1->getKeys();
    $keys2 = $reloaded2->getKeys();
    
    expect($keys1[0])->toContain('Agent1');
    expect($keys2[0])->toContain('Agent2');
});
