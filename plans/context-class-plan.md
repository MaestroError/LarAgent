# Context Class Implementation Plan

## Overview

The Context class serves as a central orchestration layer that manages multiple storage instances for an Agent. It acts as:

1. A registration place for different storages
2. A unified API for bulk operations (save, clear, read)
3. A session identity manager
4. A provider of direct access to storage instances

**Key Design Principles:**

-   Context holds an "identity storage" that tracks all storage keys related to the agent
-   Storage instances that performed read operations stay in memory for the request lifecycle
-   Only dirty (changed/updated) storages perform save operations
-   Session identity is managed by Context and passed from Agent class
-   Identity storage key is derived from agent name + "context"

---

## Architecture

```
Agent
  └── Context (orchestrator)
        ├── SessionIdentity (session manager)
        ├── IdentityStorage (tracks all storage keys)
        └── StorageRegistry (registered Storage instances)
              ├── ChatHistoryStorage
              ├── StateStorage
              ├── MemoryStorage
              └── ... (custom storages)
```

---

## Current State Analysis

### Existing Components

| Component            | Location                                      | Purpose                       |
| -------------------- | --------------------------------------------- | ----------------------------- |
| `SessionIdentity`    | `src/Context/SessionIdentity.php`             | Session identity with scoping |
| `Storage` (abstract) | `src/Context/Abstract/Storage.php`            | Base storage abstraction      |
| `StorageManager`     | `src/Context/StorageManager.php`              | Multi-driver management       |
| `ChatHistoryStorage` | `src/Context/Storages/ChatHistoryStorage.php` | Chat history storage          |

### What's Missing

1. **Context class** - Central orchestrator
2. **IdentityStorage** - Tracks storage keys per agent
3. **Context Contract** - Interface for Context

---

## Phase 1: Create Context Contract

### Location

`src/Context/Contracts/Context.php`

### Interface Definition

```php
interface Context
{
    /**
     * Get the session identity
     */
    public function getIdentity(): SessionIdentity;

    /**
     * Get a registered storage by prefix/name
     * Uses storage's getStoragePrefix() as the registration key
     */
    public function getStorage(string $prefixOrClass): ?Storage;

    /**
     * Register a storage instance
     * Uses storage's getStoragePrefix() method as the registration key
     */
    public function register(Storage $storage): static;

    /**
     * Check if a storage is registered by prefix or class name
     * Accepts either a prefix string or a Storage class name
     */
    public function has(string $prefixOrClass): bool;

    /**
     * Get all registered storage names
     */
    public function getStorageNames(): array;

    /**
     * Save all dirty storages
     */
    public function save(): void;

    /**
     * Read/refresh all storages
     */
    public function read(): void;

    /**
     * Clear all storages
     */
    public function clear(): void;

    /**
     * Remove all storages from their drivers
     */
    public function remove(): void;

    /**
     * Get all storage keys tracked by this context
     */
    public function getTrackedKeys(): array;
}
```

---

## Phase 2: Create IdentityStorage

### Purpose

A specialized storage that tracks all storage keys registered within a context. This enables:

-   Listing all storages related to an agent
-   Cleanup operations
-   Key discovery

### Location

`src/Context/Storages/IdentityStorage.php`

### Key Design

-   Uses SessionIdentity data model
-   Stores each storage key
-   Extends Storage class

---

## Phase 3: Create Context Class

### Location

`src/Context/Context.php`

### Constructor

```php
public function __construct(
    SessionIdentity $identity,
    string $agentName
)
```

### Properties

| Property           | Type                     | Description                    |
| ------------------ | ------------------------ | ------------------------------ |
| `$identity`        | `SessionIdentity`        | Base identity for this context |
| `$storages`        | `array<string, Storage>` | Registered storage instances   |
| `$identityStorage` | `IdentityStorage`        | Tracks all storage keys        |
| `$driversConfig`   | `array`                  | Default driver configuration   |

### Key Methods

#### Registration API

**Note:** Each storage class defines a static `getStoragePrefix()` method (e.g., `'chat_history'`, `'state'`, `'memory'`). This prefix serves as the registration key in Context, ensuring consistent naming and avoiding manual key management.

**Storage Abstract Class Change:** The `getStoragePrefix()` method must be changed from `abstract protected` to `abstract public static` to allow calling it without instantiation. This enables:

-   `ChatHistoryStorage::getStoragePrefix()` → `'chat_history'`
-   Passing class names to `has()` method: `$context->has(ChatHistoryStorage::class)`

```php
/**
 * Register a storage instance
 * Uses storage's getStoragePrefix() as the registration key
 * Automatically tracks the storage key in identity storage
 */
public function register(Storage $storage): static
{
    $prefix = $storage->getStoragePrefix();
    $this->storages[$prefix] = $storage;
    $this->identityStorage->addKey($storage->getIdentity()->getKey());
    return $this;
}

/**
 * Create and register a storage from class name
 * Registration key is derived from storage's getStoragePrefix()
 */
public function make(string $storageClass, array $driversConfig = []): Storage
{
    $storage = new $storageClass(
        $this->driversConfig,
        $this->identity,
    );
    $this->register($storage);
    return $storage;
}
```

#### Bulk Operations

```php
/**
 * Save all dirty storages + identity storage
 */
public function save(): void
{
    foreach ($this->storages as $storage) {
        $storage->save();
    }
    $this->identityStorage->save();
}

/**
 * Read/refresh all storages
 */
public function read(): void
{
    foreach ($this->storages as $storage) {
        $storage->read();
    }
}

/**
 * Clear all storages (marks as dirty, empty)
 */
public function clear(): void
{
    foreach ($this->storages as $storage) {
        $storage->clear();
    }
}

/**
 * Remove all storages from drivers and clear identity storage
 */
public function remove(): void
{
    foreach ($this->storages as $storage) {
        $storage->remove();
    }
    $this->identityStorage->clear();
    $this->identityStorage->save();
}
```

#### Access API

```php
/**
 * Get storage by prefix (from getStoragePrefix())
 */
public function getStorage(string $prefixOrClass): ?Storage
{
    $prefix = $this->resolvePrefix($prefixOrClass);
    return $this->storages[$prefix] ?? null;
}

/**
 * Check if storage exists by prefix or class name
 * Accepts either a prefix string or a Storage class name
 *
 * Examples:
 *   $context->has('chat_history')              // by prefix
 *   $context->has(ChatHistoryStorage::class)   // by class name
 */
public function has(string $prefixOrClass): bool
{
    $prefix = $this->resolvePrefix($prefixOrClass);
    return isset($this->storages[$prefix]);
}

/**
 * Resolve prefix from string or class name
 * If the string is a valid Storage class, calls its static getStoragePrefix()
 */
protected function resolvePrefix(string $prefixOrClass): string
{
    if (class_exists($prefixOrClass) && is_subclass_of($prefixOrClass, Storage::class)) {
        return $prefixOrClass::getStoragePrefix();
    }
    return $prefixOrClass;
}

/**
 * Magic getter for direct storage access
 * Allows: $context->chat_history instead of $context->getStorage('chat_history')
 * Note: Uses storage prefix (e.g., 'chat_history', 'state', 'memory')
 */
public function __get(string $prefix): ?Storage
{
    return $this->getStorage($prefix);
}

/**
 * Get all tracked keys from identity storage
 */
public function getTrackedKeys(): array
{
    return $this->identityStorage->getKeys();
}
```

---

## Phase 4: Context Events

### Purpose

Enable extensibility through Laravel's event system.

### Events to Create

| Event               | When Fired                  | Payload                           |
| ------------------- | --------------------------- | --------------------------------- |
| `ContextCreated`    | After context instantiation | `$context`                        |
| `StorageRegistered` | After storage registration  | `$context`, `$prefix`, `$storage` |
| `ContextSaving`     | Before save operation       | `$context`                        |
| `ContextSaved`      | After save operation        | `$context`                        |
| `ContextClearing`   | Before clear operation      | `$context`                        |
| `ContextCleared`    | After clear operation       | `$context`                        |

### Location

`src/Events/Context/`

---

## Phase 5: Integration with Agent

### Changes to Agent Class

1. Add `$context` property
2. Initialize Context in constructor with session identity
3. Register default storages (chat history)
4. Provide access to context via `context()` method

---

## Phase 6: Lifecycle Management

### Request Lifecycle

1. **Initialization**: Context created, identity storage loaded
2. **Operation**: Storages lazy-load on first access, tracked in memory
3. **Termination**: Only dirty storages save, identity storage updated

### Implementation

```php
// In Context class

public function __destruct()
{
    // Auto-save dirty storages on termination
    $this->save();
}
```

### Note on Auto-Save

Auto-save in destructor is a fallback. Explicit `save()` calls are recommended for control.

---

## Phase 7: Testing

### Unit Tests

#### Context Tests

Location: `tests/LarAgent/Context/ContextTest.php`

```php
test('Context registers storage and tracks key', function () {
    // ...
});

test('Context saves only dirty storages', function () {
    // ...
});

test('Context provides direct storage access via magic getter', function () {
    // ...
});

test('Context clears all storages', function () {
    // ...
});

test('Context removes all storages and clears identity', function () {
    // ...
});
```

#### IdentityStorage Tests

Location: `tests/LarAgent/Context/IdentityStorageTest.php`

```php
test('IdentityStorage tracks keys', function () {
    // ...
});

test('IdentityStorage persists keys across instances', function () {
    // ...
});
```

---

## File Summary

### New Files

| File                                             | Purpose              |
| ------------------------------------------------ | -------------------- |
| `src/Context/Contracts/Context.php`              | Context interface    |
| `src/Context/Context.php`                        | Main Context class   |
| `src/Context/Storages/IdentityStorage.php`       | Key tracking storage |
| `src/Events/Context/ContextCreated.php`          | Event                |
| `src/Events/Context/StorageRegistered.php`       | Event                |
| `src/Events/Context/ContextSaving.php`           | Event                |
| `src/Events/Context/ContextSaved.php`            | Event                |
| `src/Events/Context/ContextClearing.php`         | Event                |
| `src/Events/Context/ContextCleared.php`          | Event                |
| `tests/LarAgent/Context/ContextTest.php`         | Tests                |
| `tests/LarAgent/Context/IdentityStorageTest.php` | Tests                |

### Modified Files

| File                                | Changes                                                     |
| ----------------------------------- | ----------------------------------------------------------- |
| `src/Agent.php`                     | Add context property and initialization                     |
| `src/Context/Abstract/Storage.php`  | Change `getStoragePrefix()` from protected to public static |
| `src/Context/Contracts/Storage.php` | Add static `getStoragePrefix()` to interface                |

---

## Implementation Order

1. 🔲 **Phase 1:** Create Context Contract
2. 🔲 **Phase 2:** Create IdentityStorage
3. 🔲 **Phase 3:** Create Context Class
4. 🔲 **Phase 4:** Create Context Events
5. 🔲 **Phase 5:** Integration with Agent (deferred - requires broader changes)
6. 🔲 **Phase 6:** Lifecycle management
7. 🔲 **Phase 7:** Testing

---

## Key Principles Adherence

### Ease of Use ✓

-   Simple registration API: `$context->register($storage)` (uses `getStoragePrefix()` automatically)
-   Direct access via magic getter: `$context->chat_history` (uses storage prefix)
-   Bulk operations: `$context->save()`, `$context->clear()`

### Flexibility ✓

-   Custom storages can be registered
-   Driver configuration passed from Agent
-   Events for all major operations

### Ease of Extension ✓

-   Events enable hooking into lifecycle
-   Storage classes can be extended
-   Identity storage tracks all keys for discovery

### Standardization ✓

-   Follows existing Storage abstraction pattern
-   Uses Laravel Events pattern
-   Consistent with existing LarAgent patterns

---

## Considerations

### Breaking Changes

This plan introduces **one breaking change**:

-   **`getStoragePrefix()` visibility change**: Changed from `abstract protected` to `abstract public static` in Storage abstract class. Any custom Storage implementations must update their method signature.

Other changes are additive:

-   Existing ChatHistory implementations continue to work
-   Agent class changes are internal

### Future Integration

Full integration with Agent class should be a separate phase after:

1. Context class is stable and tested
2. ChatHistoryStorage is fully integrated
3. Migration path is defined for existing implementations

### Alternative Approaches Considered

1. **Context as trait** - Rejected: Composition over inheritance for better testability
2. **Global context registry** - Rejected: Per-agent isolation is cleaner
3. **Context without IdentityStorage** - Rejected: Key tracking is essential for discovery and cleanup
