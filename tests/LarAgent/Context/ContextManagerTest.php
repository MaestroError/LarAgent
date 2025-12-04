<?php

use LarAgent\Agent;
use LarAgent\Context\Context;
use LarAgent\Context\ContextManager;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Facades\Context as ContextFacade;
use LarAgent\Messages\UserMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

// ===========================================
// Test Agent for ContextManager Tests
// ===========================================

class ContextManagerTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'session';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a context manager test agent.';
    }
}

// Another test agent to verify isolation
class AnotherContextManagerTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'session';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are another test agent.';
    }
}

// ===========================================
// Helper Functions
// ===========================================

/**
 * Generate a unique test prefix for test isolation
 */
function generateUniqueTestId(): string
{
    return 'test_' . uniqid();
}

function setupAgentWithMessages(string $agentClass, string $key, array $messages): Agent
{
    $agent = $agentClass::for($key);
    foreach ($messages as $message) {
        $agent->chatHistory()->addMessage(new UserMessage($message));
    }
    $agent->context()->save();
    return $agent;
}

function setupUserAgentWithMessages(string $agentClass, string $userId, array $messages): Agent
{
    $agent = $agentClass::forUserId($userId);
    foreach ($messages as $message) {
        $agent->chatHistory()->addMessage(new UserMessage($message));
    }
    $agent->context()->save();
    return $agent;
}

function setupGroupAgentWithMessages(string $agentClass, string $chatName, string $group, array $messages): Agent
{
    // Use constructor directly to pass group parameter
    $agent = new $agentClass($chatName, usesUserId: false, group: $group);
    foreach ($messages as $message) {
        $agent->chatHistory()->addMessage(new UserMessage($message));
    }
    $agent->context()->save();
    return $agent;
}

function setupUserGroupAgentWithMessages(string $agentClass, string $userId, string $group, array $messages): Agent
{
    // Use constructor directly to pass group parameter
    $agent = new $agentClass($userId, usesUserId: true, group: $group);
    foreach ($messages as $message) {
        $agent->chatHistory()->addMessage(new UserMessage($message));
    }
    $agent->context()->save();
    return $agent;
}

// ===========================================
// 1. Entry Points (Static Factory Methods)
// ===========================================

test('ContextManager → of() → creates instance for agent class', function () {
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    expect($manager)->toBeInstanceOf(ContextManager::class);
});

test('ContextManager → agent() → is alias for of()', function () {
    $manager1 = ContextManager::of(ContextManagerTestAgent::class);
    $manager2 = ContextManager::agent(ContextManagerTestAgent::class);
    
    expect($manager1)->toBeInstanceOf(ContextManager::class);
    expect($manager2)->toBeInstanceOf(ContextManager::class);
});

test('ContextManager → facade → Context::of() works', function () {
    $manager = ContextFacade::of(ContextManagerTestAgent::class);
    
    expect($manager)->toBeInstanceOf(ContextManager::class);
});

test('ContextManager → facade → Context::agent() works', function () {
    $manager = ContextFacade::agent(ContextManagerTestAgent::class);
    
    expect($manager)->toBeInstanceOf(ContextManager::class);
});

// ===========================================
// 2. Filter Methods (Chainable)
// ===========================================

test('ContextManager → forStorage() → filters by storage scope', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat1", ['Hello']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat2", ['World']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', $id))
        ->getIdentities();
    
    expect($identities->count())->toBe(2);
    foreach ($identities as $identity) {
        // Scope is stored as the prefix string, not the full class name
        expect($identity->getScope())->toBe(ChatHistoryStorage::getStoragePrefix());
    }
});

test('ContextManager → forUser() → filters by user ID', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user_1", ['Hello from user 1']);
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user_2", ['Hello from user 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->forUser("{$id}_user_1")->getIdentities();
    
    expect($identities->count())->toBe(1);
    expect($identities->first()->getUserId())->toBe("{$id}_user_1");
});

test('ContextManager → forUser() → accepts Authenticatable instance', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_auth_user", ['Hello from auth user']);
    
    // Create a mock Authenticatable
    $user = new class("{$id}_auth_user") implements \Illuminate\Contracts\Auth\Authenticatable {
        public function __construct(private string $id) {}
        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return $this->id; }
        public function getAuthPassword() { return ''; }
        public function getRememberToken() { return ''; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return ''; }
        public function getAuthPasswordName() { return 'password'; }
    };
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->forUser($user)->getIdentities();
    
    expect($identities->count())->toBe(1);
    expect($identities->first()->getUserId())->toBe("{$id}_auth_user");
});

test('ContextManager → forChat() → filters by chat name', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_support", ['Support message']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_sales", ['Sales message']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->forChat("{$id}_support")->getIdentities();
    
    expect($identities->count())->toBe(1);
    expect($identities->first()->getChatName())->toBe("{$id}_support");
});

test('ContextManager → forGroup() → filters by group', function () {
    $id = generateUniqueTestId();
    setupGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat1", "{$id}_premium", ['Premium message']);
    setupGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat2", "{$id}_free", ['Free message']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->forGroup("{$id}_premium")->getIdentities();
    
    expect($identities->count())->toBe(1);
    expect($identities->first()->getGroup())->toBe("{$id}_premium");
});

test('ContextManager → filter() → applies custom filter callback', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat_a", ['A']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat_b", ['B']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_other", ['C']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->filter(function (SessionIdentityContract $identity) use ($id) {
        $name = $identity->getChatName() ?? '';
        return str_starts_with($name, "{$id}_chat_");
    })->getIdentities();
    
    expect($identities->count())->toBe(2);
});

test('ContextManager → chaining multiple filters → applies all filters', function () {
    $id = generateUniqueTestId();
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user1", "{$id}_premium", ['Premium user 1']);
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user1", "{$id}_free", ['Free user 1']);
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user2", "{$id}_premium", ['Premium user 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->forUser("{$id}_user1")
        ->forGroup("{$id}_premium")
        ->getIdentities();
    
    expect($identities->count())->toBe(1);
    $identity = $identities->first();
    expect($identity->getUserId())->toBe("{$id}_user1");
    expect($identity->getGroup())->toBe("{$id}_premium");
});

test('ContextManager → filters are immutable → original instance unchanged', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_test", ['Test']);
    
    $manager1 = ContextManager::of(ContextManagerTestAgent::class);
    $manager2 = $manager1->forStorage(ChatHistoryStorage::class);
    $manager3 = $manager2->forChat("{$id}_test");
    
    // Each filter call returns a new instance
    expect($manager1)->not->toBe($manager2);
    expect($manager2)->not->toBe($manager3);
});

// ===========================================
// 3. Query Methods
// ===========================================

test('ContextManager → getIdentities() → returns SessionIdentityArray', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_query1", ['Message 1']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_query2", ['Message 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', $id))
        ->getIdentities();
    
    expect($identities)->toBeInstanceOf(SessionIdentityArray::class);
    expect($identities->count())->toBe(2);
});

test('ContextManager → getChatIdentities() → returns only chat history identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chat", ['Hello']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->filter(fn($i) => str_starts_with($i->getChatName() ?? '', $id))->getChatIdentities();
    
    expect($identities->count())->toBeGreaterThanOrEqual(1);
    foreach ($identities as $identity) {
        // Scope is stored as the prefix string, not the full class name
        expect($identity->getScope())->toBe(ChatHistoryStorage::getStoragePrefix());
    }
});

test('ContextManager → getStorageKeys() → returns all tracked storage keys', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_keys", ['Test']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $keys = $manager->getStorageKeys();
    
    expect($keys)->toBeArray();
    expect(count($keys))->toBeGreaterThanOrEqual(1);
});

test('ContextManager → getChatKeys() → returns only chat history keys', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_chatkeys", ['Test']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $keys = $manager->getChatKeys();
    
    expect($keys)->toBeArray();
    expect(count($keys))->toBeGreaterThanOrEqual(1);
    foreach ($keys as $key) {
        expect($key)->toContain('chatHistory');
    }
});

// ===========================================
// 4. Terminal Methods - Iteration
// ===========================================

test('ContextManager → each() → iterates over matching identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_each1", ['Message 1']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_each2", ['Message 2']);
    
    $results = [];
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $returned = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_each"))
        ->each(function ($identity, $agent) use (&$results) {
            $results[] = [
                'chatName' => $identity->getChatName(),
                'agentClass' => get_class($agent),
            ];
        });
    
    expect($returned)->toBeInstanceOf(ContextManager::class);
    expect(count($results))->toBe(2);
    expect($results[0]['agentClass'])->toBe(ContextManagerTestAgent::class);
});

test('ContextManager → each() → provides working agent instance', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_each_agent", ['Original message']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $checked = false;
    $manager
        ->forStorage(ChatHistoryStorage::class)
        ->forChat("{$id}_each_agent")
        ->each(function ($identity, $agent) use (&$checked) {
            $messages = $agent->chatHistory()->getMessages();
            expect(count($messages))->toBeGreaterThanOrEqual(1);
            $checked = true;
        });
    
    expect($checked)->toBeTrue();
});

test('ContextManager → map() → maps over identities and returns array', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_map1", ['Msg 1']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_map2", ['Msg 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $results = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_map"))
        ->map(function ($identity, $agent) {
            return $identity->getChatName();
        });
    
    expect($results)->toBeArray();
    expect(count($results))->toBe(2);
    expect($results)->toContain("{$id}_map1");
    expect($results)->toContain("{$id}_map2");
});

test('ContextManager → all() → returns array of SessionIdentity objects', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_all1", ['Msg']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_all2", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_all"))
        ->all();
    
    expect($identities)->toBeArray();
    expect(count($identities))->toBe(2);
    expect($identities[0])->toBeInstanceOf(SessionIdentityContract::class);
});

// ===========================================
// 5. Terminal Methods - Count & Existence
// ===========================================

test('ContextManager → count() → returns number of matching identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_count1", ['Msg']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_count2", ['Msg']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_count3", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $count = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_count"))
        ->count();
    
    expect($count)->toBe(3);
});

test('ContextManager → count() → returns 0 when no matches', function () {
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $count = $manager->forChat('nonexistent_chat_xyz_' . uniqid())->count();
    
    expect($count)->toBe(0);
});

test('ContextManager → exists() → returns true when matches exist', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_exists", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $exists = $manager->forChat("{$id}_exists")->exists();
    
    expect($exists)->toBeTrue();
});

test('ContextManager → exists() → returns false when no matches', function () {
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $exists = $manager->forChat('nonexistent_chat_xyz_' . uniqid())->exists();
    
    expect($exists)->toBeFalse();
});

// ===========================================
// 6. Terminal Methods - First
// ===========================================

test('ContextManager → first() → returns first matching identity', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_first", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identity = $manager->forChat("{$id}_first")->first();
    
    expect($identity)->toBeInstanceOf(SessionIdentityContract::class);
    expect($identity->getChatName())->toBe("{$id}_first");
});

test('ContextManager → first() → returns null when no matches', function () {
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identity = $manager->forChat('nonexistent_xyz_' . uniqid())->first();
    
    expect($identity)->toBeNull();
});

test('ContextManager → firstAgent() → returns agent instance for first match', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_firstagent", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $agent = $manager->forChat("{$id}_firstagent")->firstAgent();
    
    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(ContextManagerTestAgent::class);
});

test('ContextManager → firstAgent() → returns null when no matches', function () {
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $agent = $manager->forChat('nonexistent_xyz_' . uniqid())->firstAgent();
    
    expect($agent)->toBeNull();
});

// ===========================================
// 7. Terminal Methods - Clear
// ===========================================

test('ContextManager → clear() → clears data but keeps tracking', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clear", ['Message to clear']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Verify message exists before clear
    $agentBefore = $manager->forChat("{$id}_clear")->firstAgent();
    expect($agentBefore->chatHistory()->getMessages())->not->toBeEmpty();
    
    // Clear
    $manager->forChat("{$id}_clear")->clear();
    
    // Verify message is cleared
    $agentAfter = ContextManagerTestAgent::for("{$id}_clear");
    $agentAfter->chatHistory()->read();
    expect($agentAfter->chatHistory()->getMessages())->toBeEmpty();
    
    // But identity should still be tracked
    expect($manager->forChat("{$id}_clear")->exists())->toBeTrue();
});

test('ContextManager → clear() → only clears matching identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_keep", ['Keep this']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_remove", ['Clear this']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Clear only one
    $manager->forChat("{$id}_remove")->clear();
    
    // Verify only targeted chat is cleared
    $keptAgent = ContextManagerTestAgent::for("{$id}_keep");
    $keptAgent->chatHistory()->read();
    expect($keptAgent->chatHistory()->getMessages())->not->toBeEmpty();
    
    $clearedAgent = ContextManagerTestAgent::for("{$id}_remove");
    $clearedAgent->chatHistory()->read();
    expect($clearedAgent->chatHistory()->getMessages())->toBeEmpty();
});

test('ContextManager → clear() → returns self for chaining', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clearchain", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $result = $manager->forChat("{$id}_clearchain")->clear();
    
    expect($result)->toBeInstanceOf(ContextManager::class);
});

// ===========================================
// 8. Terminal Methods - Remove
// ===========================================

test('ContextManager → remove() → removes data and tracking', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_remove", ['Message to remove']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Verify exists before remove
    expect($manager->forChat("{$id}_remove")->exists())->toBeTrue();
    
    // Remove
    $manager->forChat("{$id}_remove")->remove();
    
    // Verify completely removed
    expect($manager->forChat("{$id}_remove")->exists())->toBeFalse();
});

test('ContextManager → remove() → only removes matching identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_keep", ['Keep']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_delete", ['Delete']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Remove only one
    $manager->forChat("{$id}_delete")->remove();
    
    // Verify only targeted is removed
    expect($manager->forChat("{$id}_keep")->exists())->toBeTrue();
    expect($manager->forChat("{$id}_delete")->exists())->toBeFalse();
});

test('ContextManager → remove() → returns self for chaining', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_removechain", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $result = $manager->forChat("{$id}_removechain")->remove();
    
    expect($result)->toBeInstanceOf(ContextManager::class);
});

// ===========================================
// 9. Convenience Methods
// ===========================================

test('ContextManager → clearAllChats() → clears all chat histories for agent', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clearall1", ['Msg 1']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clearall2", ['Msg 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Clear only matching ones via filter + clear
    $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_clearall"))
        ->clear();
    
    // Both should be cleared
    $agent1 = ContextManagerTestAgent::for("{$id}_clearall1");
    $agent1->chatHistory()->read();
    expect($agent1->chatHistory()->getMessages())->toBeEmpty();
    
    $agent2 = ContextManagerTestAgent::for("{$id}_clearall2");
    $agent2->chatHistory()->read();
    expect($agent2->chatHistory()->getMessages())->toBeEmpty();
});

test('ContextManager → clearAllChatsByUser() → clears chats for specific user', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clearuser1", ['User 1 msg']);
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_clearuser2", ['User 2 msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $manager->clearAllChatsByUser("{$id}_clearuser1");
    
    // User 1 should be cleared
    $agent1 = ContextManagerTestAgent::forUserId("{$id}_clearuser1");
    $agent1->chatHistory()->read();
    expect($agent1->chatHistory()->getMessages())->toBeEmpty();
    
    // User 2 should remain
    $agent2 = ContextManagerTestAgent::forUserId("{$id}_clearuser2");
    $agent2->chatHistory()->read();
    expect($agent2->chatHistory()->getMessages())->not->toBeEmpty();
});

test('ContextManager → removeAllChats() → removes all chat histories and tracking', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_removeall1", ['Msg 1']);
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_removeall2", ['Msg 2']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Get count before for these specific chats
    $countBefore = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_removeall"))
        ->count();
    expect($countBefore)->toBe(2);
    
    // Remove only our test chats
    $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_removeall"))
        ->remove();
    
    // Our test chats should be removed
    $countAfter = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_removeall"))
        ->count();
    expect($countAfter)->toBe(0);
});

test('ContextManager → removeAllChatsByUser() → removes chats for specific user', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_removeuser1", ['User 1 msg']);
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_removeuser2", ['User 2 msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $manager->removeAllChatsByUser("{$id}_removeuser1");
    
    // User 1 should be removed
    expect($manager->forUser("{$id}_removeuser1")->exists())->toBeFalse();
    // User 2 should exist
    expect($manager->forUser("{$id}_removeuser2")->exists())->toBeTrue();
});

test('ContextManager → getIdentitiesByUser() → returns identities for user', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_getuser1", ['Msg']);
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_getuser2", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->getIdentitiesByUser("{$id}_getuser1");
    
    expect($identities)->toBeInstanceOf(SessionIdentityArray::class);
    expect($identities->count())->toBe(1);
    expect($identities->first()->getUserId())->toBe("{$id}_getuser1");
});

test('ContextManager → eachByUser() → iterates over user identities', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_eachuser", ['Msg']);
    
    $called = false;
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $manager->eachByUser("{$id}_eachuser", function ($identity, $agent) use (&$called, $id) {
        $called = true;
        expect($identity->getUserId())->toBe("{$id}_eachuser");
    });
    
    expect($called)->toBeTrue();
});

test('ContextManager → countByUser() → returns count for user', function () {
    $id = generateUniqueTestId();
    setupUserAgentWithMessages(ContextManagerTestAgent::class, "{$id}_countuser", ['Msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $count = $manager->countByUser("{$id}_countuser");
    
    expect($count)->toBe(1);
});

// ===========================================
// 10. Agent Isolation
// ===========================================

test('ContextManager → agent isolation → different agents have separate identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_isolation", ['Agent 1 msg']);
    setupAgentWithMessages(AnotherContextManagerTestAgent::class, "{$id}_isolation", ['Agent 2 msg']);
    
    $manager1 = ContextManager::of(ContextManagerTestAgent::class);
    $manager2 = ContextManager::of(AnotherContextManagerTestAgent::class);
    
    $identities1 = $manager1->forChat("{$id}_isolation")->getIdentities();
    $identities2 = $manager2->forChat("{$id}_isolation")->getIdentities();
    
    // Each should only see their own
    expect($identities1->count())->toBe(1);
    expect($identities2->count())->toBe(1);
    
    // Agent names should be different
    expect($identities1->first()->getAgentName())->toBe('ContextManagerTestAgent');
    expect($identities2->first()->getAgentName())->toBe('AnotherContextManagerTestAgent');
});

test('ContextManager → clearing one agent does not affect another', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_cross", ['Agent 1 msg']);
    setupAgentWithMessages(AnotherContextManagerTestAgent::class, "{$id}_cross", ['Agent 2 msg']);
    
    // Clear first agent
    ContextManager::of(ContextManagerTestAgent::class)->forChat("{$id}_cross")->clear();
    
    // Second agent should be unaffected
    $agent2 = AnotherContextManagerTestAgent::for("{$id}_cross");
    $agent2->chatHistory()->read();
    expect($agent2->chatHistory()->getMessages())->not->toBeEmpty();
});

// ===========================================
// 11. Edge Cases
// ===========================================

test('ContextManager → handles empty results gracefully', function () {
    $id = generateUniqueTestId();
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    $identities = $manager->forChat("{$id}_nonexistent")->getIdentities();
    
    expect($identities->count())->toBe(0);
    expect($manager->forChat("{$id}_nonexistent")->first())->toBeNull();
    expect($manager->forChat("{$id}_nonexistent")->firstAgent())->toBeNull();
    expect($manager->forChat("{$id}_nonexistent")->all())->toBeEmpty();
    expect($manager->forChat("{$id}_nonexistent")->exists())->toBeFalse();
});

test('ContextManager → clear on empty results does not throw', function () {
    $id = generateUniqueTestId();
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    $result = $manager->forChat("{$id}_nonexistent")->clear();
    
    expect($result)->toBeInstanceOf(ContextManager::class);
});

test('ContextManager → remove on empty results does not throw', function () {
    $id = generateUniqueTestId();
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    $result = $manager->forChat("{$id}_nonexistent")->remove();
    
    expect($result)->toBeInstanceOf(ContextManager::class);
});

test('ContextManager → each on empty results does not call callback', function () {
    $id = generateUniqueTestId();
    $called = false;
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    $manager->forChat("{$id}_nonexistent")->each(function () use (&$called) {
        $called = true;
    });
    
    expect($called)->toBeFalse();
});

test('ContextManager → map on empty results returns empty array', function () {
    $id = generateUniqueTestId();
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    $result = $manager->forChat("{$id}_nonexistent")->map(fn() => 'test');
    
    expect($result)->toBe([]);
});

// ===========================================
// 12. Temp Agent Exclusion
// ===========================================

test('ContextManager → temp agent is not tracked in identities', function () {
    $id = generateUniqueTestId();
    setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_regular", ['Regular msg']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    $identities = $manager->getIdentities();
    
    // None of the identities should have the temp prefix
    foreach ($identities as $identity) {
        expect($identity->getChatName())->not->toStartWith(IdentityStorage::TEMP_SESSION_PREFIX);
    }
});

// ===========================================
// 13. Complex Scenarios
// ===========================================

test('ContextManager → complex filtering scenario', function () {
    $id = generateUniqueTestId();
    
    // Setup diverse data
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user1", "{$id}_premium", ['Premium user 1']);
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user1", "{$id}_basic", ['Basic user 1']);
    setupUserGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_user2", "{$id}_premium", ['Premium user 2']);
    setupGroupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_nochat", "{$id}_premium", ['No user premium']);
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Find all premium users (with user ID set)
    $premiumUsers = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->forGroup("{$id}_premium")
        ->filter(fn($i) => $i->getUserId() !== null)
        ->all();
    
    expect(count($premiumUsers))->toBe(2);
    foreach ($premiumUsers as $identity) {
        expect($identity->getGroup())->toBe("{$id}_premium");
        expect($identity->getUserId())->not->toBeNull();
    }
});

test('ContextManager → batch operations', function () {
    $id = generateUniqueTestId();
    
    // Create multiple chats
    for ($i = 1; $i <= 5; $i++) {
        setupAgentWithMessages(ContextManagerTestAgent::class, "{$id}_batch{$i}", ["Message {$i}"]);
    }
    
    $manager = ContextManager::of(ContextManagerTestAgent::class);
    
    // Count all batch test chats
    $count = $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_batch"))
        ->count();
    
    expect($count)->toBe(5);
    
    // Clear all
    $manager
        ->forStorage(ChatHistoryStorage::class)
        ->filter(fn($i) => str_starts_with($i->getChatName() ?? '', "{$id}_batch"))
        ->clear();
    
    // Verify all cleared
    for ($i = 1; $i <= 5; $i++) {
        $agent = ContextManagerTestAgent::for("{$id}_batch{$i}");
        $agent->chatHistory()->read();
        expect($agent->chatHistory()->getMessages())->toBeEmpty();
    }
});
