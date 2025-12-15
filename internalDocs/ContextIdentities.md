# Context and Identities

The Context system in LarAgent provides a unified way to manage multiple storage instances (chat history, usage tracking, custom storages) for agents. Session Identities are the mechanism for isolating data between different users, sessions, and agent instances.

## Overview

The Context system consists of:

1. **Context** - Central orchestration layer managing all storages for an agent
2. **SessionIdentity** - Data object identifying a unique user/session/agent combination
3. **IdentityStorage** - Tracks all registered storage identities for an agent
4. **Storage Abstractions** - Base classes for building custom storages

## Session Identity

A `SessionIdentity` uniquely identifies a storage location based on:

- **agentName** - The name of the agent class
- **chatName** - A custom chat/session identifier
- **userId** - The user ID (when using per-user agents)
- **group** - An optional group for categorization
- **scope** - Storage type prefix (e.g., 'chatHistory', 'usage')

### Creating Agents with Different Identities

```php
use App\AiAgents\SupportAgent;

// 1. For a specific user (using Authenticatable)
$agent = SupportAgent::forUser($user);
// Identity: agentName=SupportAgent, userId={user_id}

// 2. For a specific user ID
$agent = SupportAgent::forUserId('user-123');
// Identity: agentName=SupportAgent, userId=user-123

// 3. For a custom session/chat key
$agent = SupportAgent::for('support-ticket-456');
// Identity: agentName=SupportAgent, chatName=support-ticket-456

// 4. With a random key (for one-off conversations)
$agent = SupportAgent::make();
// Identity: agentName=SupportAgent, chatName={random}
```

### With Groups

```php
class SupportAgent extends Agent
{
    protected $group = 'support';
}

// Group affects the storage key prefix
$agent = SupportAgent::forUserId('user-123');
// Key: support_user-123 (instead of SupportAgent_user-123)
```

### Reconstructing from Identity

```php
use LarAgent\Facades\Context;

// Get an existing identity
$identity = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->first();

// Reconstruct the agent from identity
if ($identity) {
    $agent = SupportAgent::fromIdentity($identity);
}
```

## Context Object

The Context manages all storages for an agent instance:

### Accessing Context

```php
// Via agent
$context = $agent->context();

// Get the base identity
$identity = $context->getIdentity();

// Get registered storage names
$names = $context->getStorageNames(); // ['chatHistory', 'usage', ...]
```

### Registering Custom Storages

```php
// Using make() to create and register
$storage = $context->make(MyCustomStorage::class);

// Or register an existing instance
$context->register($myStorageInstance);
```

### Bulk Operations

```php
// Save all dirty storages
$context->save();

// Read/refresh all storages from drivers
$context->read();

// Clear all storages (marks as dirty, sets to empty)
$context->clear();

// Remove all storages completely from drivers
$context->remove();
```

### Getting Tracked Keys

```php
// Get all storage keys tracked by this context
$allKeys = $context->getTrackedKeys();

// Get keys filtered by prefix
$chatKeys = $context->getTrackedKeysByPrefix('chatHistory');

// Get identities filtered by scope
$chatIdentities = $context->getTrackedIdentitiesByScope('chatHistory');
```

## IdentityStorage

IdentityStorage tracks all registered storage identities for an agent, enabling operations across all sessions:

```php
// Get the identity storage
$identityStorage = $context->getIdentityStorage();

// Get all tracked identities
$identities = $identityStorage->getIdentities();

// Filter by scope
$chatIdentities = $identityStorage->getIdentitiesByScope('chatHistory');

// Get all storage keys
$keys = $identityStorage->getKeys();

// Get keys by prefix
$chatKeys = $identityStorage->getKeysByPrefix('chatHistory');
```

## Context Facade

The `Context` facade provides a fluent API for managing contexts across all agent instances:

### Agent-Based Access (of/agent)

```php
use LarAgent\Facades\Context;
use App\AiAgents\SupportAgent;

// Creates a ContextManager with full agent access
$manager = Context::of(SupportAgent::class);
// or
$manager = Context::agent(SupportAgent::class);
```

### Named Access (Lightweight)

```php
use LarAgent\Context\Drivers\CacheStorage;

// Creates a NamedContextManager without initializing agent
$manager = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class]);
```

### Filtering Operations

```php
// Filter by user
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clearAllChats();

// Filter by chat name
Context::of(SupportAgent::class)
    ->forChat('support-ticket-456')
    ->removeAllChats();

// Filter by group
Context::of(SupportAgent::class)
    ->forGroup('premium')
    ->each(function ($identity, $agent) {
        // Process premium user chats
    });

// Filter by storage type
Context::of(SupportAgent::class)
    ->forStorage(ChatHistoryStorage::class)
    ->count();

// Custom filter
Context::of(SupportAgent::class)
    ->filter(fn($identity) => str_starts_with($identity->getChatName(), 'vip-'))
    ->count();
```

### Query Operations

```php
// Count matching identities
$total = Context::of(SupportAgent::class)->count();

// Check if any match
$exists = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->exists();

// Get first matching identity
$identity = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->first();

// Get first as agent instance
$agent = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->firstAgent();

// Get all identities
$identities = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->all();
```

### Iteration

```php
// ContextManager - receives identity AND agent
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->each(function ($identity, $agent) {
        echo "Chat: " . $identity->getChatName();
        // $agent is fully initialized
    });

// Map to collect results
$results = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->map(function ($identity, $agent) {
        return [
            'chat' => $identity->getChatName(),
            'messageCount' => count($agent->chatHistory()->getMessages()),
        ];
    });
```

### Terminal Actions

```php
// Clear chat data (data cleared, keys remain tracked)
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clear();

// Remove completely (data and tracking removed)
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->remove();

// Convenience methods
Context::of(SupportAgent::class)->clearAllChats();
Context::of(SupportAgent::class)->removeAllChats();
Context::of(SupportAgent::class)->clearAllChatsByUser('user-123');
Context::of(SupportAgent::class)->removeAllChatsByUser('user-123');
```

## Events

The Context system dispatches events:

| Event | Description |
|-------|-------------|
| `ContextCreated` | When a context is initialized |
| `ContextSaving` | Before saving all storages |
| `ContextSaved` | After saving all storages |
| `ContextReading` | Before reading all storages |
| `ContextRead` | After reading all storages |
| `ContextClearing` | Before clearing all storages |
| `ContextCleared` | After clearing all storages |
| `StorageRegistered` | When a storage is registered |
| `IdentityAdding` | Before adding an identity |
| `IdentityAdded` | After adding an identity |
| `IdentityStorageSaving` | Before saving identity storage |
| `IdentityStorageSaved` | After saving identity storage |
| `IdentityStorageLoaded` | After loading identity storage |

### Listening to Events

```php
use LarAgent\Events\Context\ContextSaved;
use Illuminate\Support\Facades\Event;

Event::listen(ContextSaved::class, function ($event) {
    $context = $event->context;
    Log::info('Context saved', [
        'agent' => $context->getIdentity()->getAgentName(),
    ]);
});
```

## Filter Immutability

Filters create new instances, leaving the original unchanged:

```php
$base = Context::of(SupportAgent::class);
$filtered = $base->forUser('user-123');

// $base still has no filters
echo $base->count();      // All identities
echo $filtered->count();  // Only user-123's identities
```

## Common Use Cases

### Admin Dashboard - Clear Old Chats

```php
$cleared = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->filter(function ($identity) {
        // Custom logic for old chats
        return true;
    })
    ->clearAllChats();

Log::info("Cleared $cleared old chat sessions");
```

### User Account Deletion

```php
Context::of(SupportAgent::class)
    ->forUser($userId)
    ->removeAllChats();

Context::of(BillingAgent::class)
    ->forUser($userId)
    ->removeAllChats();
```

### Export User Chat History

```php
$exports = Context::of(SupportAgent::class)
    ->forUser($userId)
    ->map(function ($identity, $agent) {
        return [
            'chat_name' => $identity->getChatName(),
            'messages' => $agent->chatHistory()->toArray(),
        ];
    });
```

### Check Active Sessions

```php
$hasActiveSessions = Context::of(SupportAgent::class)
    ->forUser($userId)
    ->forGroup('active')
    ->exists();
```

## Storage Key Format

Storage keys are generated based on identity components:

```
{scope}_{group|agentName}_{userId|chatName}
```

Examples:
- `chatHistory_SupportAgent_user-123`
- `usage_support_user-123` (with group)
- `chatHistory_MyAgent_default` (no user or chat name)

This format ensures:
- Isolation between different storage types (via scope)
- Isolation between agents (via agentName or group)
- Isolation between users/sessions (via userId or chatName)
