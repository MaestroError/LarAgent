<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use LarAgent\Context\Context;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\UserMessage;

// ===========================================
// Helper Functions
// ===========================================

function createIntegrationIdentity(string $agentName, string $chatName = 'test_chat', ?string $userId = null, ?string $group = null): SessionIdentity
{
    return new SessionIdentity(
        agentName: $agentName,
        chatName: $chatName,
        userId: $userId,
        group: $group
    );
}

/**
 * Helper to get text content from a message
 */
function getMessageText($message): string
{
    return (string) $message->getContent();
}

// ===========================================
// Agent Isolation Tests
// ===========================================

describe('Context Key Isolation', function () {

    test('different agents have isolated storage keys', function () {
        $driver = new InMemoryStorage;

        // Agent 1
        $identity1 = createIntegrationIdentity('Agent1', 'chat_1');
        $context1 = new Context($identity1, [$driver]);
        $storage1 = $context1->make(ChatHistoryStorage::class);
        $storage1->addMessage(new UserMessage('Hello from Agent1'));
        $context1->save();

        // Agent 2 with same chat name but different agent
        $identity2 = createIntegrationIdentity('Agent2', 'chat_1');
        $context2 = new Context($identity2, [$driver]);
        $storage2 = $context2->make(ChatHistoryStorage::class);
        $storage2->addMessage(new UserMessage('Hello from Agent2'));
        $context2->save();

        // Reload and verify isolation
        $reloadIdentity1 = createIntegrationIdentity('Agent1', 'chat_1');
        $reloadContext1 = new Context($reloadIdentity1, [$driver]);
        $reloadStorage1 = $reloadContext1->make(ChatHistoryStorage::class);
        $reloadStorage1->read();

        $reloadIdentity2 = createIntegrationIdentity('Agent2', 'chat_1');
        $reloadContext2 = new Context($reloadIdentity2, [$driver]);
        $reloadStorage2 = $reloadContext2->make(ChatHistoryStorage::class);
        $reloadStorage2->read();

        // Each agent should only see their own messages
        expect($reloadStorage1->getMessages()->count())->toBe(1);
        expect($reloadStorage2->getMessages()->count())->toBe(1);

        $msg1 = $reloadStorage1->getLastMessage();
        $msg2 = $reloadStorage2->getLastMessage();

        expect(getMessageText($msg1))->toBe('Hello from Agent1');
        expect(getMessageText($msg2))->toBe('Hello from Agent2');
    });

    test('different sessions have isolated storage keys', function () {
        $driver = new InMemoryStorage;

        // Session 1
        $identity1 = createIntegrationIdentity('TestAgent', 'session_1');
        $context1 = new Context($identity1, [$driver]);
        $storage1 = $context1->make(ChatHistoryStorage::class);
        $storage1->addMessage(new UserMessage('Session 1 message'));
        $context1->save();

        // Session 2 with same agent but different chat
        $identity2 = createIntegrationIdentity('TestAgent', 'session_2');
        $context2 = new Context($identity2, [$driver]);
        $storage2 = $context2->make(ChatHistoryStorage::class);
        $storage2->addMessage(new UserMessage('Session 2 message'));
        $context2->save();

        // Reload and verify isolation
        $reloadIdentity1 = createIntegrationIdentity('TestAgent', 'session_1');
        $reloadContext1 = new Context($reloadIdentity1, [$driver]);
        $reloadStorage1 = $reloadContext1->make(ChatHistoryStorage::class);
        $reloadStorage1->read();

        $reloadIdentity2 = createIntegrationIdentity('TestAgent', 'session_2');
        $reloadContext2 = new Context($reloadIdentity2, [$driver]);
        $reloadStorage2 = $reloadContext2->make(ChatHistoryStorage::class);
        $reloadStorage2->read();

        expect(getMessageText($reloadStorage1->getLastMessage()))->toBe('Session 1 message');
        expect(getMessageText($reloadStorage2->getLastMessage()))->toBe('Session 2 message');
    });

    test('user isolation within same agent', function () {
        $driver = new InMemoryStorage;

        // User 1
        $identity1 = createIntegrationIdentity('TestAgent', 'chat', 'user_1');
        $context1 = new Context($identity1, [$driver]);
        $storage1 = $context1->make(ChatHistoryStorage::class);
        $storage1->addMessage(new UserMessage('User 1 private message'));
        $context1->save();

        // User 2
        $identity2 = createIntegrationIdentity('TestAgent', 'chat', 'user_2');
        $context2 = new Context($identity2, [$driver]);
        $storage2 = $context2->make(ChatHistoryStorage::class);
        $storage2->addMessage(new UserMessage('User 2 private message'));
        $context2->save();

        // Reload and verify user isolation
        $reloadIdentity1 = createIntegrationIdentity('TestAgent', 'chat', 'user_1');
        $reloadContext1 = new Context($reloadIdentity1, [$driver]);
        $reloadStorage1 = $reloadContext1->make(ChatHistoryStorage::class);
        $reloadStorage1->read();

        expect(getMessageText($reloadStorage1->getLastMessage()))->toBe('User 1 private message');
        expect($reloadStorage1->getMessages()->count())->toBe(1);
    });

    test('group isolation within same agent', function () {
        $driver = new InMemoryStorage;

        // Group A
        $identity1 = createIntegrationIdentity('TestAgent', 'chat', null, 'group_a');
        $context1 = new Context($identity1, [$driver]);
        $storage1 = $context1->make(ChatHistoryStorage::class);
        $storage1->addMessage(new UserMessage('Group A message'));
        $context1->save();

        // Group B
        $identity2 = createIntegrationIdentity('TestAgent', 'chat', null, 'group_b');
        $context2 = new Context($identity2, [$driver]);
        $storage2 = $context2->make(ChatHistoryStorage::class);
        $storage2->addMessage(new UserMessage('Group B message'));
        $context2->save();

        // Reload and verify group isolation
        $reloadIdentity1 = createIntegrationIdentity('TestAgent', 'chat', null, 'group_a');
        $reloadContext1 = new Context($reloadIdentity1, [$driver]);
        $reloadStorage1 = $reloadContext1->make(ChatHistoryStorage::class);
        $reloadStorage1->read();

        expect(getMessageText($reloadStorage1->getLastMessage()))->toBe('Group A message');
        expect($reloadStorage1->getMessages()->count())->toBe(1);
    });

});

// ===========================================
// Full Lifecycle Tests
// ===========================================

describe('Context Full Lifecycle', function () {

    test('complete lifecycle: create -> register -> add data -> save -> read', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('LifecycleAgent', 'lifecycle_chat');

        // Phase 1: Create context and register storage
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);

        expect($context->has('chatHistory'))->toBeTrue();

        // Phase 2: Add data
        $storage->addMessage(new UserMessage('What is 2+2?'));
        $storage->addMessage(new AssistantMessage('4'));
        $storage->addMessage(new UserMessage('And 3+3?'));
        $storage->addMessage(new AssistantMessage('6'));

        expect($storage->getMessages()->count())->toBe(4);

        // Phase 3: Save
        $context->save();

        // Phase 4: Read with new context
        $newIdentity = createIntegrationIdentity('LifecycleAgent', 'lifecycle_chat');
        $newContext = new Context($newIdentity, [$driver]);
        $newStorage = $newContext->make(ChatHistoryStorage::class);
        $newStorage->read();

        expect($newStorage->getMessages()->count())->toBe(4);
        expect(getMessageText($newStorage->getLastMessage()))->toBe('6');
    });

    test('context recovery: save -> new instance -> read -> verify data', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('RecoveryAgent', 'recovery_chat');

        // Initial context with data
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);
        $storage->addMessage(new UserMessage('Message before crash'));
        $context->save();

        // Simulate "crash" - context goes out of scope
        unset($context);
        unset($storage);

        // Recovery: new context instance
        $recoveredIdentity = createIntegrationIdentity('RecoveryAgent', 'recovery_chat');
        $recoveredContext = new Context($recoveredIdentity, [$driver]);
        $recoveredStorage = $recoveredContext->make(ChatHistoryStorage::class);
        $recoveredStorage->read();

        // Verify data recovered
        expect($recoveredStorage->getMessages()->count())->toBe(1);
        expect(getMessageText($recoveredStorage->getLastMessage()))->toBe('Message before crash');
    });

    test('multiple save cycles preserve conversation history', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('ConversationAgent', 'conversation');

        // Turn 1
        $context1 = new Context($identity, [$driver]);
        $storage1 = $context1->make(ChatHistoryStorage::class);
        $storage1->addMessage(new UserMessage('Hello'));
        $storage1->addMessage(new AssistantMessage('Hi there!'));
        $context1->save();

        // Turn 2 (new request)
        $identity2 = createIntegrationIdentity('ConversationAgent', 'conversation');
        $context2 = new Context($identity2, [$driver]);
        $storage2 = $context2->make(ChatHistoryStorage::class);
        $storage2->read();
        $storage2->addMessage(new UserMessage('How are you?'));
        $storage2->addMessage(new AssistantMessage('I am doing well!'));
        $context2->save();

        // Turn 3 (another request)
        $identity3 = createIntegrationIdentity('ConversationAgent', 'conversation');
        $context3 = new Context($identity3, [$driver]);
        $storage3 = $context3->make(ChatHistoryStorage::class);
        $storage3->read();
        $storage3->addMessage(new UserMessage('Goodbye'));
        $storage3->addMessage(new AssistantMessage('Bye!'));
        $context3->save();

        // Final verification
        $finalIdentity = createIntegrationIdentity('ConversationAgent', 'conversation');
        $finalContext = new Context($finalIdentity, [$driver]);
        $finalStorage = $finalContext->make(ChatHistoryStorage::class);
        $finalStorage->read();

        expect($finalStorage->getMessages()->count())->toBe(6);

        // Check first and last message content
        $messages = $finalStorage->getMessages();
        expect(getMessageText($messages->first()))->toBe('Hello');
        expect(getMessageText($messages->last()))->toBe('Bye!');
    });

});

// ===========================================
// Multi-Storage Integration Tests
// ===========================================

describe('Context with Multiple Storages', function () {

    test('context manages multiple storage types independently', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('MultiStorageAgent', 'multi_chat');
        $context = new Context($identity, [$driver]);

        // Create chat history storage
        $chatStorage = $context->make(ChatHistoryStorage::class);
        $chatStorage->addMessage(new UserMessage('Hello'));

        // Identity storage is auto-created
        $identityStorage = $context->getIdentityStorage();

        // Save both
        $context->save();

        // Verify both are tracked
        expect($context->getStorageNames())->toContain('chatHistory');
        expect($context->getTrackedKeys())->toContain($chatStorage->getIdentity()->getKey());
    });

    test('context read loads all registered storages', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('ReadAllAgent', 'read_all');

        // Setup initial data
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);
        $storage->addMessage(new UserMessage('Initial message'));
        $context->save();

        // New context - register storage then call read to load all
        $newIdentity = createIntegrationIdentity('ReadAllAgent', 'read_all');
        $newContext = new Context($newIdentity, [$driver]);
        $newStorage = $newContext->make(ChatHistoryStorage::class);

        // After make - not loaded yet (lazy loading)
        // Call read to force load
        $newContext->read();
        expect($newStorage->getMessages()->count())->toBe(1);
    });

    test('context clear removes data from all storages', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('ClearAllAgent', 'clear_all');

        // Setup initial data
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);
        $storage->addMessage(new UserMessage('Message to clear'));
        $context->save();

        // Clear all
        $context->clear();

        // Verify cleared
        expect($storage->getMessages()->count())->toBe(0);

        // Save cleared state
        $context->save();

        // New context should have empty data
        $newIdentity = createIntegrationIdentity('ClearAllAgent', 'clear_all');
        $newContext = new Context($newIdentity, [$driver]);
        $newStorage = $newContext->make(ChatHistoryStorage::class);
        $newStorage->read();

        expect($newStorage->getMessages()->count())->toBe(0);
    });

});

// ===========================================
// Identity Storage Integration
// ===========================================

describe('IdentityStorage Integration with Context', function () {

    test('registered storages are automatically tracked in IdentityStorage', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('TrackingAgent', 'tracking');
        $context = new Context($identity, [$driver]);

        // Register storage
        $storage = $context->make(ChatHistoryStorage::class);

        // Identity should be tracked
        $identityStorage = $context->getIdentityStorage();
        expect($identityStorage->hasKey($storage->getIdentity()->getKey()))->toBeTrue();
    });

    test('IdentityStorage persists tracked identities across context instances', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('PersistentAgent', 'persistent');

        // First context - register and save
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);
        $storageKey = $storage->getIdentity()->getKey();
        $context->save();

        // Second context - should have tracked identity
        $newIdentity = createIntegrationIdentity('PersistentAgent', 'persistent');
        $newContext = new Context($newIdentity, [$driver]);
        $newContext->getIdentityStorage()->read();

        expect($newContext->getTrackedKeys())->toContain($storageKey);
    });

    test('context remove cleans up IdentityStorage tracking', function () {
        $driver = new InMemoryStorage;
        $identity = createIntegrationIdentity('CleanupAgent', 'cleanup');

        // Setup
        $context = new Context($identity, [$driver]);
        $storage = $context->make(ChatHistoryStorage::class);
        $storage->addMessage(new UserMessage('Data'));
        $context->save();

        expect($context->getTrackedKeys())->not->toBeEmpty();

        // Remove all
        $context->remove();
        $context->save();

        // New context should have no tracked keys
        $newIdentity = createIntegrationIdentity('CleanupAgent', 'cleanup');
        $newContext = new Context($newIdentity, [$driver]);
        $newContext->getIdentityStorage()->read();

        expect($newContext->getTrackedKeys())->toBeEmpty();
    });

});
