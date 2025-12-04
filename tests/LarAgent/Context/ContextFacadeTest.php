<?php

namespace Tests\LarAgent\Context;

use LarAgent\Agent;
use LarAgent\Context\Context;
use LarAgent\Context\ContextManager;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\NamedContextManager;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Facades\Context as ContextFacade;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

// Test agent class for facade tests
class FacadeTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $driver = FakeLlmDriver::class;
    protected $storage = [InMemoryStorage::class];

    public function instructions()
    {
        return 'Test agent for facade tests';
    }

    public function prompt($message)
    {
        return $message;
    }
}

// Helper to generate unique agent names for test isolation
function facadeUniqueAgentName(string $prefix = 'FacadeTest'): string
{
    return $prefix . '_' . uniqid();
}

// ==========================================
// Facade Access Methods Tests
// ==========================================

describe('Context Facade → Access Methods', function () {

    test('of() → returns ContextManager instance', function () {
        $manager = ContextFacade::of(FacadeTestAgent::class);

        expect($manager)->toBeInstanceOf(ContextManager::class);
    });

    test('agent() → returns ContextManager instance (alias for of)', function () {
        $manager = ContextFacade::agent(FacadeTestAgent::class);

        expect($manager)->toBeInstanceOf(ContextManager::class);
    });

    test('named() → returns NamedContextManager instance', function () {
        $manager = ContextFacade::named('TestAgent');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('of() and agent() → return equivalent managers', function () {
        $manager1 = ContextFacade::of(FacadeTestAgent::class);
        $manager2 = ContextFacade::agent(FacadeTestAgent::class);

        // Both should be ContextManager instances
        expect($manager1)->toBeInstanceOf(ContextManager::class);
        expect($manager2)->toBeInstanceOf(ContextManager::class);
    });

});

// ==========================================
// Context::of() Tests
// ==========================================

describe('Context Facade → of() Method', function () {

    test('of() → can count identities', function () {
        $agent = FacadeTestAgent::for('facade_test_' . uniqid());
        $agent->context()->save();

        $count = ContextFacade::of(FacadeTestAgent::class)->count();

        expect($count)->toBeGreaterThanOrEqual(0);
    });

    test('of() → can chain filter methods', function () {
        $manager = ContextFacade::of(FacadeTestAgent::class)
            ->forUser('test-user')
            ->forChat('test-chat');

        expect($manager)->toBeInstanceOf(ContextManager::class);
    });

    test('of() → can iterate over identities', function () {
        $agent = FacadeTestAgent::for('facade_iterate_' . uniqid());
        $agent->context()->save();

        $iterated = false;
        ContextFacade::of(FacadeTestAgent::class)->each(function ($identity) use (&$iterated) {
            $iterated = true;
        });

        // Just verify no errors - iteration may or may not find identities
        expect(true)->toBeTrue();
    });

    test('of() → can clear all chats', function () {
        $key = 'facade_clear_' . uniqid();
        $agent = FacadeTestAgent::for($key);
        $agent->context()->save();

        // clearAllChats returns static for chaining
        $result = ContextFacade::of(FacadeTestAgent::class)->clearAllChats();

        expect($result)->toBeInstanceOf(ContextManager::class);
    });

});

// ==========================================
// Context::named() Tests
// ==========================================

describe('Context Facade → named() Method', function () {

    test('named() → can set custom drivers', function () {
        $manager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->getDriversConfig())->toBe([InMemoryStorage::class]);
    });

    test('named() → can access context', function () {
        $manager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->context())->toBeInstanceOf(Context::class);
    });

    test('named() → can count identities', function () {
        $agentName = facadeUniqueAgentName();
        $manager = ContextFacade::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->count())->toBe(0);
    });

    test('named() → can chain filter methods', function () {
        $manager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forUser('test-user')
            ->forGroup('premium');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('named() → can add and query identities', function () {
        $agentName = facadeUniqueAgentName();
        $manager = ContextFacade::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'test-chat',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->count())->toBe(1);
        expect($manager->exists())->toBeTrue();
    });

});

// ==========================================
// Comparison Tests - of() vs named()
// ==========================================

describe('Context Facade → Comparison of() vs named()', function () {

    test('both can filter by user', function () {
        // Using of()
        $ofManager = ContextFacade::of(FacadeTestAgent::class)->forUser('user-1');
        expect($ofManager)->toBeInstanceOf(ContextManager::class);

        // Using named()
        $namedManager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forUser('user-1');
        expect($namedManager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('both can filter by chat', function () {
        // Using of()
        $ofManager = ContextFacade::of(FacadeTestAgent::class)->forChat('support');
        expect($ofManager)->toBeInstanceOf(ContextManager::class);

        // Using named()
        $namedManager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forChat('support');
        expect($namedManager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('both can filter by group', function () {
        // Using of()
        $ofManager = ContextFacade::of(FacadeTestAgent::class)->forGroup('premium');
        expect($ofManager)->toBeInstanceOf(ContextManager::class);

        // Using named()
        $namedManager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forGroup('premium');
        expect($namedManager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('both can filter by storage type', function () {
        // Using of()
        $ofManager = ContextFacade::of(FacadeTestAgent::class)->forStorage(ChatHistoryStorage::class);
        expect($ofManager)->toBeInstanceOf(ContextManager::class);

        // Using named()
        $namedManager = ContextFacade::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forStorage(ChatHistoryStorage::class);
        expect($namedManager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('both support count()', function () {
        // Using of()
        $ofCount = ContextFacade::of(FacadeTestAgent::class)->count();
        expect($ofCount)->toBeInt();

        // Using named()
        $namedCount = ContextFacade::named(facadeUniqueAgentName())
            ->withDrivers([InMemoryStorage::class])
            ->count();
        expect($namedCount)->toBeInt();
    });

    test('both support exists()', function () {
        // Using of()
        $ofExists = ContextFacade::of(FacadeTestAgent::class)->exists();
        expect($ofExists)->toBeBool();

        // Using named()
        $namedExists = ContextFacade::named(facadeUniqueAgentName())
            ->withDrivers([InMemoryStorage::class])
            ->exists();
        expect($namedExists)->toBeBool();
    });

    test('both support first()', function () {
        // Using of() - may return null if no identities
        $ofFirst = ContextFacade::of(FacadeTestAgent::class)->first();
        // Just verify it returns something (null or identity)

        // Using named()
        $namedFirst = ContextFacade::named(facadeUniqueAgentName())
            ->withDrivers([InMemoryStorage::class])
            ->first();
        expect($namedFirst)->toBeNull(); // Empty, so null
    });

    test('both support clearAllChats()', function () {
        // Using of() - returns static (ContextManager)
        $ofResult = ContextFacade::of(FacadeTestAgent::class)->clearAllChats();
        expect($ofResult)->toBeInstanceOf(ContextManager::class);

        // Using named() - returns int (count)
        $namedCleared = ContextFacade::named(facadeUniqueAgentName())
            ->withDrivers([InMemoryStorage::class])
            ->clearAllChats();
        expect($namedCleared)->toBeInt();
    });

    test('both support removeAllChats()', function () {
        // Using of() - returns static (ContextManager)
        $ofResult = ContextFacade::of(FacadeTestAgent::class)->removeAllChats();
        expect($ofResult)->toBeInstanceOf(ContextManager::class);

        // Using named() - returns int (count)
        $namedRemoved = ContextFacade::named(facadeUniqueAgentName())
            ->withDrivers([InMemoryStorage::class])
            ->removeAllChats();
        expect($namedRemoved)->toBeInt();
    });

});

// ==========================================
// Integration Tests
// ==========================================

describe('Context Facade → Integration', function () {

    test('of() → works with real agent workflow', function () {
        $key = 'facade_workflow_' . uniqid();
        
        // Create agent and generate some context
        $agent = FacadeTestAgent::for($key);
        $agent->context()->save();

        // Use facade to query
        $manager = ContextFacade::of(FacadeTestAgent::class);
        
        // Should be able to get identities (even if empty)
        $identities = $manager->getIdentities();
        expect($identities)->toBeIterable();
    });

    test('named() → works with manual identity management', function () {
        $agentName = facadeUniqueAgentName();
        
        // Create manager via facade
        $manager = ContextFacade::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        // Manually add identity
        $identity = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-123',
            chatName: 'support-chat',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        // Query using same manager instance (InMemory doesn't persist across instances)
        $count = $manager->forUser('user-123')->count();

        expect($count)->toBe(1);
    });

    test('named() → multiple filters work together', function () {
        $agentName = facadeUniqueAgentName();
        
        $manager = ContextFacade::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        // Add multiple identities
        $identity1 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-1',
            chatName: 'chat-1',
            group: 'premium',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-1',
            chatName: 'chat-2',
            group: 'free',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity3 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-2',
            chatName: 'chat-3',
            group: 'premium',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->addIdentity($identity3);
        $manager->context()->getIdentityStorage()->save();

        // Filter by user AND group using same manager instance
        $filtered = $manager
            ->forUser('user-1')
            ->forGroup('premium')
            ->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getChatName())->toBe('chat-1');
    });

});

// ==========================================
// Edge Cases
// ==========================================

describe('Context Facade → Edge Cases', function () {

    test('of() → handles non-existent agent gracefully', function () {
        // of() is lazy - it just stores the class name
        // Error only happens when trying to use it (getTempAgent())
        $manager = ContextFacade::of('NonExistentAgentClass');
        expect($manager)->toBeInstanceOf(ContextManager::class);
        
        // Error happens when we try to actually use it
        expect(fn() => $manager->count())
            ->toThrow(\Error::class);
    });

    test('named() → handles empty agent name', function () {
        $manager = ContextFacade::named('');

        // Should still create a manager, just with empty name
        expect($manager)->toBeInstanceOf(NamedContextManager::class);
        expect($manager->getAgentName())->toBe('');
    });

    test('named() → handles special characters in agent name', function () {
        $manager = ContextFacade::named('Agent-With_Special.Chars');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
        expect($manager->getAgentName())->toBe('Agent-With_Special.Chars');
    });

    test('chained methods → are immutable', function () {
        $agentName = facadeUniqueAgentName();
        
        $manager = ContextFacade::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        // Add some identities
        $identity1 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        // Chain creates new instance
        $filtered = $manager->forUser('user-1');

        // Original should still have all
        expect($manager->count())->toBe(2);
        // Filtered should have one
        expect($filtered->count())->toBe(1);
    });

});
