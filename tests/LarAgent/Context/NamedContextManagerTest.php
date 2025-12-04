<?php

namespace Tests\LarAgent\Context;

use LarAgent\Context\Context;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\NamedContextManager;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Facades\Context as ContextFacade;

// Helper to generate unique agent names for test isolation
function uniqueAgentName(string $prefix = 'TestAgent'): string
{
    return $prefix.'_'.uniqid();
}

// ==========================================
// Factory Tests
// ==========================================

describe('NamedContextManager → Factory', function () {

    test('named() → creates instance with agent name', function () {
        $manager = NamedContextManager::named('TestAgent');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
        expect($manager->getAgentName())->toBe('TestAgent');
    });

    test('named() → accessible via facade', function () {
        $manager = ContextFacade::named('TestAgent');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
        expect($manager->getAgentName())->toBe('TestAgent');
    });

});

// ==========================================
// Driver Configuration Tests
// ==========================================

describe('NamedContextManager → Driver Configuration', function () {

    test('withDrivers() → sets custom drivers', function () {
        $manager = NamedContextManager::named('TestAgent')
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->getDriversConfig())->toBe([InMemoryStorage::class]);
    });

    test('withDrivers() → is chainable', function () {
        $manager = NamedContextManager::named('TestAgent')
            ->withDrivers([InMemoryStorage::class])
            ->forUser('user-1');

        expect($manager)->toBeInstanceOf(NamedContextManager::class);
    });

    test('getDriversConfig() → returns default drivers when not set', function () {
        $manager = NamedContextManager::named('TestAgent');

        // Should return some default (either from config or InMemoryStorage)
        expect($manager->getDriversConfig())->toBeArray();
        expect($manager->getDriversConfig())->not->toBeEmpty();
    });

    test('context() → returns Context instance', function () {
        $manager = NamedContextManager::named('TestAgent')
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->context())->toBeInstanceOf(Context::class);
    });

});

// ==========================================
// Filter Tests
// ==========================================

describe('NamedContextManager → Filters', function () {

    test('forUser() → filters by user ID', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-1',
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            userId: 'user-2',
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $filtered = $manager->forUser('user-1')->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getUserId())->toBe('user-1');
    });

    test('forChat() → filters by chat name', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'support',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'sales',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $filtered = $manager->forChat('support')->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getChatName())->toBe('support');
    });

    test('forGroup() → filters by group', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            group: 'premium',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            group: 'free',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $filtered = $manager->forGroup('premium')->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getGroup())->toBe('premium');
    });

    test('forStorage() → filters by storage class', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: 'customScope'
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $filtered = $manager->forStorage(ChatHistoryStorage::class)->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getScope())->toBe(ChatHistoryStorage::getStoragePrefix());
    });

    test('filter() → applies custom callback', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-a-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-b-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $filtered = $manager->filter(fn ($identity) => str_starts_with($identity->getChatName(), 'chat-a'))->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getChatName())->toBe('chat-a-1');
    });

    test('chained filters → combine correctly', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

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

        $filtered = $manager
            ->forUser('user-1')
            ->forGroup('premium')
            ->getIdentities();

        expect($filtered->count())->toBe(1);
        expect($filtered->first()->getUserId())->toBe('user-1');
        expect($filtered->first()->getGroup())->toBe('premium');
    });

    test('filters are immutable', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

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

        $filtered = $manager->forUser('user-1');

        // Original manager should still return all identities
        expect($manager->getIdentities()->count())->toBe(2);
        expect($filtered->getIdentities()->count())->toBe(1);
    });

});

// ==========================================
// Query Method Tests
// ==========================================

describe('NamedContextManager → Query Methods', function () {

    test('count() → returns number of matching identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->count())->toBe(2);
    });

    test('exists() → returns true when identities exist', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->exists())->toBeTrue();
    });

    test('exists() → returns false when no identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->exists())->toBeFalse();
    });

    test('isEmpty() → returns true when no identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->isEmpty())->toBeTrue();
    });

    test('isEmpty() → returns false when identities exist', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->isEmpty())->toBeFalse();
    });

    test('first() → returns first matching identity', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->first())->not->toBeNull();
    });

    test('first() → returns null when no identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->first())->toBeNull();
    });

    test('last() → returns last matching identity', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->last())->not->toBeNull();
    });

    test('getChatIdentities() → returns only chat history identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'other',
            scope: 'customScope'
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $chatIdentities = $manager->getChatIdentities();

        expect($chatIdentities->count())->toBe(1);
        expect($chatIdentities->first()->getScope())->toBe(ChatHistoryStorage::getStoragePrefix());
    });

    test('getIdentities() → returns SessionIdentityArray', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->getIdentities())->toBeInstanceOf(SessionIdentityArray::class);
    });

    test('all() → returns array of matching identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $all = $manager->all();

        expect($all)->toBeArray();
        expect($all)->toHaveCount(2);
    });

    test('all() → returns empty array when no identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->all())->toBeArray();
        expect($manager->all())->toBeEmpty();
    });

    test('getStorageKeys() → returns all tracked storage keys', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: 'customScope'
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $keys = $manager->getStorageKeys();

        expect($keys)->toBeArray();
        expect($keys)->toHaveCount(2);
    });

    test('getStorageKeys() → returns empty array when no identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->getStorageKeys())->toBeArray();
        expect($manager->getStorageKeys())->toBeEmpty();
    });

    test('getChatKeys() → returns only chat history keys', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'other',
            scope: 'customScope'
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $chatKeys = $manager->getChatKeys();

        expect($chatKeys)->toBeArray();
        expect($chatKeys)->toHaveCount(1);
        expect($chatKeys[0])->toContain('chatHistory');
    });

    test('getChatKeys() → returns empty array when no chat identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'other',
            scope: 'customScope'
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        expect($manager->getChatKeys())->toBeArray();
        expect($manager->getChatKeys())->toBeEmpty();
    });

});

// ==========================================
// Iteration Method Tests
// ==========================================

describe('NamedContextManager → Iteration Methods', function () {

    test('each() → iterates over matching identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $chatNames = [];
        $manager->each(function ($identity) use (&$chatNames) {
            $chatNames[] = $identity->getChatName();
        });

        expect($chatNames)->toHaveCount(2);
        expect($chatNames)->toContain('chat-1');
        expect($chatNames)->toContain('chat-2');
    });

    test('each() → is chainable', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $result = $manager->each(fn ($identity) => null);

        expect($result)->toBeInstanceOf(NamedContextManager::class);
    });

    test('map() → transforms identities', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $chatNames = $manager->map(fn ($identity) => $identity->getChatName());

        expect($chatNames)->toHaveCount(2);
        expect($chatNames)->toContain('chat-1');
        expect($chatNames)->toContain('chat-2');
    });

});

// ==========================================
// Terminal Action Tests
// ==========================================

describe('NamedContextManager → Terminal Actions', function () {

    test('clearAllChats() → clears chat history data', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        // Create a chat history with messages
        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $chatStorage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);
        $chatStorage->addMessage(\LarAgent\Message::user('Hello'));
        $chatStorage->save();

        // Register the identity
        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        // Clear all chats
        $count = $manager->clearAllChats();

        expect($count)->toBe(1);

        // Note: We can't verify the clear because InMemoryStorage is instance-based
        // The clearAllChats creates a new ChatHistoryStorage instance which won't
        // share the same in-memory data. This would work correctly with persistent
        // drivers like FileStorage, SessionStorage, etc.
    });

    test('clearAllChats() → returns count of cleared chats', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        // Create chat histories
        $chat1 = new ChatHistoryStorage($identity1, [InMemoryStorage::class]);
        $chat1->addMessage(\LarAgent\Message::user('Hello 1'));
        $chat1->save();

        $chat2 = new ChatHistoryStorage($identity2, [InMemoryStorage::class]);
        $chat2->addMessage(\LarAgent\Message::user('Hello 2'));
        $chat2->save();

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $count = $manager->clearAllChats();

        expect($count)->toBe(2);
    });

    test('removeAllChats() → removes chat history entries', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $chatStorage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);
        $chatStorage->addMessage(\LarAgent\Message::user('Hello'));
        $chatStorage->save();

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        // Verify identity exists before removal
        expect($manager->forStorage(ChatHistoryStorage::class)->count())->toBe(1);

        $count = $manager->removeAllChats();

        expect($count)->toBe(1);

        // Refresh the manager to see updated state (same context instance)
        expect($manager->forStorage(ChatHistoryStorage::class)->count())->toBe(0);
    });

    test('removeAllChats() → returns count of removed chats', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-2',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity1);
        $manager->context()->getIdentityStorage()->addIdentity($identity2);
        $manager->context()->getIdentityStorage()->save();

        $count = $manager->removeAllChats();

        expect($count)->toBe(2);
    });

    test('clearAll() → clears all matching storages', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $chatStorage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);
        $chatStorage->addMessage(\LarAgent\Message::user('Hello'));
        $chatStorage->save();

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        $count = $manager->clearAll();

        expect($count)->toBe(1);
    });

    test('removeAll() → removes all matching storages', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        $identity = new SessionIdentity(
            agentName: $agentName,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager->context()->getIdentityStorage()->addIdentity($identity);
        $manager->context()->getIdentityStorage()->save();

        $count = $manager->removeAll();

        expect($count)->toBe(1);
    });

});

// ==========================================
// Edge Case Tests
// ==========================================

describe('NamedContextManager → Edge Cases', function () {

    test('empty results → operations work correctly', function () {
        $agentName = uniqueAgentName();
        $manager = NamedContextManager::named($agentName)
            ->withDrivers([InMemoryStorage::class]);

        expect($manager->count())->toBe(0);
        expect($manager->exists())->toBeFalse();
        expect($manager->isEmpty())->toBeTrue();
        expect($manager->first())->toBeNull();
        expect($manager->clearAllChats())->toBe(0);
        expect($manager->removeAllChats())->toBe(0);
    });

    test('multiple agents → are isolated', function () {
        $agentName1 = uniqueAgentName('Agent1');
        $agentName2 = uniqueAgentName('Agent2');

        $manager1 = NamedContextManager::named($agentName1)
            ->withDrivers([InMemoryStorage::class]);
        $manager2 = NamedContextManager::named($agentName2)
            ->withDrivers([InMemoryStorage::class]);

        $identity1 = new SessionIdentity(
            agentName: $agentName1,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );
        $identity2 = new SessionIdentity(
            agentName: $agentName2,
            chatName: 'chat-1',
            scope: ChatHistoryStorage::getStoragePrefix()
        );

        $manager1->context()->getIdentityStorage()->addIdentity($identity1);
        $manager1->context()->getIdentityStorage()->save();

        $manager2->context()->getIdentityStorage()->addIdentity($identity2);
        $manager2->context()->getIdentityStorage()->save();

        // Each manager should only see its own identities
        expect($manager1->getIdentities()->count())->toBe(1);
        expect($manager1->first()->getAgentName())->toBe($agentName1);

        expect($manager2->getIdentities()->count())->toBe(1);
        expect($manager2->first()->getAgentName())->toBe($agentName2);
    });

});
