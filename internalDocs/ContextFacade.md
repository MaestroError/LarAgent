# Context Facade Documentation

The Context Facade provides an Eloquent-like fluent API for managing agent contexts, chat histories, and storage operations. It offers two distinct approaches for accessing context data.

## Access Methods

### `Context::of(AgentClass::class)` / `Context::agent(AgentClass::class)`

Creates a `ContextManager` instance that requires a full agent class. This approach:

-   **Initializes a temporary agent instance** internally to access context
-   **Has access to agent configuration** (drivers, storage settings, etc.)
-   **Provides agent instances** in callbacks (`each`, `map`)
-   **Can recreate agents from identities** using `fromIdentity()`

```php
use LarAgent\Facades\Context;
use App\AiAgents\SupportAgent;

// Using of()
Context::of(SupportAgent::class)->clearAllChats();

// Using agent() - alias for of()
Context::agent(SupportAgent::class)->clearAllChats();
```

**Best for:** Operations that need full agent functionality, when you need to interact with agents, or when agent configuration matters.

### `Context::named(string $agentName)`

Creates a `NamedContextManager` instance using just an agent name string. This approach:

-   **Lightweight** - doesn't initialize any agent class
-   **Requires explicit driver configuration** via `withDrivers()`
-   **Only provides identities** in callbacks (no agent instances)
-   **Works without agent class definition** - useful for admin tools

```php
use LarAgent\Facades\Context;
use LarAgent\Context\Drivers\CacheStorage;

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->clearAllChats();
```

**Best for:** Administrative tasks, cleanup scripts, operations outside agent context, or when you don't need agent functionality.

## Comparison Table

| Feature                    | `of()` / `agent()`        | `named()`                       |
| -------------------------- | ------------------------- | ------------------------------- |
| Returns                    | `ContextManager`          | `NamedContextManager`           |
| Requires agent class       | ✅ Yes                    | ❌ No                           |
| Initializes agent          | ✅ Yes (temp instance)    | ❌ No                           |
| Needs `withDrivers()`      | ❌ No (uses agent config) | ✅ Yes (or uses config default) |
| `each()` callback args     | `($identity, $agent)`     | `($identity)`                   |
| `map()` callback args      | `($identity, $agent)`     | `($identity)`                   |
| `firstAgent()` method      | ✅ Available              | ❌ Not available                |
| `clearAllChats()` returns  | `static` (chainable)      | `int` (count)                   |
| `removeAllChats()` returns | `static` (chainable)      | `int` (count)                   |

---

## Filter Methods

All filter methods are chainable and create immutable instances (original instance unchanged).

### `forUser(string $userId)`

Filter identities by user ID.

```php
// Using of()
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clearAllChats();

// Using named()
Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->clearAllChats();
```

### `forChat(string $chatName)`

Filter identities by chat/session name.

```php
Context::of(SupportAgent::class)
    ->forChat('support-ticket-456')
    ->clear();

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forChat('support-ticket-456')
    ->clearAllChats();
```

### `forGroup(string $group)`

Filter identities by group.

```php
Context::of(SupportAgent::class)
    ->forGroup('premium')
    ->each(function ($identity, $agent) {
        // Process premium user chats
    });

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forGroup('premium')
    ->each(function ($identity) {
        // Process premium user identities
    });
```

### `forStorage(string $storageClass)`

Filter identities by storage type/scope.

```php
use LarAgent\Context\Storages\ChatHistoryStorage;

Context::of(SupportAgent::class)
    ->forStorage(ChatHistoryStorage::class)
    ->count();

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forStorage(ChatHistoryStorage::class)
    ->count();
```

### `filter(callable $callback)`

Add a custom filter callback. Receives `SessionIdentityContract`, returns `bool`.

```php
Context::of(SupportAgent::class)
    ->filter(function ($identity) {
        // Custom logic - return true to include
        return str_starts_with($identity->getChatName(), 'vip-');
    })
    ->count();

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->filter(fn($identity) => $identity->getUserId() !== null)
    ->count();
```

### Chaining Multiple Filters

Filters can be chained for complex queries:

```php
// All filters are AND-ed together
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->forGroup('premium')
    ->forStorage(ChatHistoryStorage::class)
    ->filter(fn($identity) => $identity->getChatName() !== 'archived')
    ->each(function ($identity, $agent) {
        // Process matching identities
    });
```

---

## Query Methods

### `count(): int`

Get the count of matching identities.

```php
$totalChats = Context::of(SupportAgent::class)->count();

$userChats = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->count();

$namedCount = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->count();
```

### `exists(): bool`

Check if any identities match the filters.

```php
$hasChats = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->exists();

if ($hasChats) {
    // User has chat history
}
```

### `first(): ?SessionIdentityContract`

Get the first matching identity or null.

```php
$identity = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->first();

if ($identity) {
    echo $identity->getChatName();
    echo $identity->getUserId();
    echo $identity->getGroup();
}
```

### `firstAgent(): ?Agent` (ContextManager only)

Get the first matching identity as an agent instance.

```php
$agent = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->firstAgent();

if ($agent) {
    // Interact with the agent
    $response = $agent->respond('Hello!');
}
```

### `all(): array`

Collect all matching identities as an array.

```php
$identities = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->all();

foreach ($identities as $identity) {
    echo $identity->getKey();
}
```

### `getIdentities(): SessionIdentityArray`

Get identities as a `SessionIdentityArray` collection.

```php
$identities = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->getIdentities();

// SessionIdentityArray provides collection methods
$filtered = $identities->filter(fn($i) => $i->getGroup() === 'premium');
```

### `getChatIdentities(): SessionIdentityArray`

Get identities filtered by ChatHistoryStorage scope.

```php
$chatIdentities = Context::of(SupportAgent::class)
    ->getChatIdentities();
```

### `getStorageKeys(): array`

Get all tracked storage keys.

```php
$keys = Context::of(SupportAgent::class)->getStorageKeys();
// Returns: ['chatHistory_SupportAgent_user-123_chat-1', ...]
```

### `getChatKeys(): array`

Get all chat history keys.

```php
$chatKeys = Context::of(SupportAgent::class)->getChatKeys();
```

---

## Iteration Methods

### `each(callable $callback): static`

Iterate over matching identities.

```php
// ContextManager - receives identity AND agent
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->each(function ($identity, $agent) {
        echo "Chat: " . $identity->getChatName();
        // $agent is a fully initialized agent instance
    });

// NamedContextManager - receives only identity
Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->each(function ($identity) {
        echo "Chat: " . $identity->getChatName();
    });
```

### `map(callable $callback): array`

Map over identities and collect results.

```php
// ContextManager
$results = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->map(function ($identity, $agent) {
        return [
            'chat' => $identity->getChatName(),
            'messageCount' => count($agent->getMessages()),
        ];
    });

// NamedContextManager
$chatNames = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->map(fn($identity) => $identity->getChatName());
```

---

## Terminal Action Methods

### `clear(): static` (ContextManager only)

Clear data from matching storages. Data is cleared but keys remain tracked.

```php
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->forStorage(ChatHistoryStorage::class)
    ->clear();
```

### `remove(): static` (ContextManager only)

Remove matching storages entirely. Both data and tracking keys are removed.

```php
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->forStorage(ChatHistoryStorage::class)
    ->remove();
```

### `clearAllChats()`

Clear all chat histories matching filters.

```php
// ContextManager - returns static (chainable)
Context::of(SupportAgent::class)
    ->clearAllChats()
    ->removeAllChats(); // Can chain

// NamedContextManager - returns int (count of cleared)
$clearedCount = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->clearAllChats();

echo "Cleared $clearedCount chats";
```

### `removeAllChats()`

Remove all chat histories matching filters.

```php
// ContextManager - returns static (chainable)
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->removeAllChats();

// NamedContextManager - returns int (count of removed)
$removedCount = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->removeAllChats();
```

### `clearAllChatsByUser(string $userId): static` (ContextManager only)

Shorthand for `forUser()->forStorage(ChatHistoryStorage)->clear()`.

```php
Context::of(SupportAgent::class)
    ->clearAllChatsByUser('user-123');
```

### `removeAllChatsByUser(string $userId): static` (ContextManager only)

Shorthand for `forUser()->forStorage(ChatHistoryStorage)->remove()`.

```php
Context::of(SupportAgent::class)
    ->removeAllChatsByUser('user-123');
```

### `clearAll(): int` (NamedContextManager only)

Clear all registered storages for matching identities.

```php
$count = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->clearAll();
```

### `removeAll(): int` (NamedContextManager only)

Remove all storage entries for matching identities.

```php
$count = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->removeAll();
```

---

## NamedContextManager Specific Methods

### `withDrivers(array $driversConfig): static`

Set custom driver configuration.

```php
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;

Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class, FileStorage::class])
    ->clearAllChats();
```

### `getAgentName(): string`

Get the agent name.

```php
$manager = Context::named('SupportAgent');
echo $manager->getAgentName(); // "SupportAgent"
```

### `getDriversConfig(): array`

Get the drivers configuration.

```php
$manager = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class]);

$drivers = $manager->getDriversConfig();
// Returns: [CacheStorage::class]
```

### `context(): Context`

Get the underlying Context instance.

```php
$context = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->context();

// Access context directly
$identityStorage = $context->getIdentityStorage();
```

### `isEmpty(): bool`

Check if no identities match (opposite of `exists()`).

```php
$isEmpty = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->isEmpty();
```

### `last(): ?SessionIdentityContract`

Get the last matching identity.

```php
$lastIdentity = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->last();
```

---

## Common Use Cases

### Admin Dashboard - Clear Old Chats

```php
// Using named() for admin tool (no agent class needed)
$cleared = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->filter(function ($identity) {
        // Clear chats older than 30 days (custom logic)
        return true; // Add your date logic here
    })
    ->clearAllChats();

Log::info("Cleared $cleared old chat sessions");
```

### User Account Deletion - Remove All User Data

```php
// Using of() to access full agent functionality
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
            'messages' => $agent->getMessages(),
            'created_at' => $identity->getKey(),
        ];
    });
```

### Check User Has Active Sessions

```php
$hasActiveSessions = Context::of(SupportAgent::class)
    ->forUser($userId)
    ->forGroup('active')
    ->exists();
```

### Batch Process Premium User Chats

```php
Context::of(SupportAgent::class)
    ->forGroup('premium')
    ->each(function ($identity, $agent) {
        // Apply premium processing to each chat
        $agent->addTool(new PremiumSupportTool());
    });
```

---

## Filter Immutability

Filters create new instances, leaving the original unchanged:

```php
$base = Context::of(SupportAgent::class);

$filtered = $base->forUser('user-123');

// $base still has no filters
echo $base->count();      // All identities
echo $filtered->count();  // Only user-123's identities
```

This allows building reusable query bases:

```php
$premiumBase = Context::of(SupportAgent::class)->forGroup('premium');

$premiumUser1 = $premiumBase->forUser('user-1')->count();
$premiumUser2 = $premiumBase->forUser('user-2')->count();
$allPremium = $premiumBase->count();
```
