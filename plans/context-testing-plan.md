# Context/Storage System Testing Plan

## Status: ✅ COMPLETE

**All phases implemented and passing!**

| Phase | Test File                    | Tests | Assertions | Status      |
| ----- | ---------------------------- | ----- | ---------- | ----------- |
| 1     | ContextTest.php              | 43    | ~80        | ✅ Complete |
| 2     | IdentityStorageTest.php      | 27    | ~50        | ✅ Complete |
| 3     | SessionIdentityArrayTest.php | 28    | ~56        | ✅ Complete |
| 4     | HasContextTraitTest.php      | 36    | ~70        | ✅ Complete |
| 5     | ContextEventsTest.php        | 41    | ~80        | ✅ Complete |
| 6     | ContextIntegrationTest.php   | 13    | ~25        | ✅ Complete |
| 7     | ConfigTest.php               | 16    | ~30        | ✅ Complete |

**Total New Tests: 204 tests (original estimate: 81)**
**Total Context Tests: 337 tests with 683 assertions**

### Bugs Found & Fixed During Testing

1. **Context::make() parameter order** - Fixed in `src/Context/Context.php`
2. **HasContext::setupContext()** - Fixed incorrect method call in `src/Context/Traits/HasContext.php`
3. **IdentityStorage::addIdentity()** - Fixed to only dispatch `IdentityAdded` event when identity is actually added (not duplicate)

---

## Overview

This document outlines the comprehensive testing plan for the Context/Storage abstraction layer. The goal is to achieve full test coverage for all components introduced in the context-class-plan implementation.

---

## Current Test Coverage Analysis

### ✅ Well Tested Components

| Component             | Test File                       | Notes                                             |
| --------------------- | ------------------------------- | ------------------------------------------------- |
| Storage Abstract      | `StorageTest.php`               | All CRUD operations, dirty tracking, lazy loading |
| SessionIdentity       | `StorageTest.php`               | Scope, key generation, serialization              |
| StorageManager        | `StorageManagerTest.php`        | Multi-driver, fallback, error handling            |
| InMemoryStorage       | `StorageDriversTest.php`        | Full coverage                                     |
| CacheStorage          | `StorageDriversTest.php`        | Mocked facade tests                               |
| SessionStorage        | `StorageDriversTest.php`        | Mocked facade tests                               |
| FileStorage           | `StorageDriversTest.php`        | Mocked facade tests                               |
| ChatHistoryStorage    | `ChatHistoryStorageTest.php`    | Messages, metadata, events                        |
| EloquentStorage       | `EloquentStorageTest.php`       | With database migrations                          |
| SimpleEloquentStorage | `SimpleEloquentStorageTest.php` | Basic CRUD                                        |

### ❌ Components Requiring Tests

| Component                  | Priority  | Reason                                  |
| -------------------------- | --------- | --------------------------------------- |
| Context Class              | 🔴 High   | Core orchestrator - completely untested |
| IdentityStorage            | 🔴 High   | Key tracking storage - untested         |
| SessionIdentityArray       | 🟡 Medium | DataModel for identity collection       |
| HasContext Trait           | 🟡 Medium | Agent integration trait                 |
| Context Events             | 🟡 Medium | 8 events need verification              |
| IdentityStorage Events     | 🟡 Medium | 5 events need verification              |
| Config-based instantiation | 🟡 Medium | Default driver resolution               |

---

## Phase 1: Context Class Tests (Priority: High)

### File: `tests/LarAgent/Context/ContextTest.php`

#### 1.1 Construction & Identity Management

```php
test('Context can be constructed with identity and drivers config')
test('Context builds context identity from session identity')
test('Context initializes identity storage on construction')
test('Context dispatches ContextCreated event on construction')
test('getIdentity returns the session identity')
test('getContextIdentity returns the context-specific identity')
test('getDriversConfig returns the configured drivers')
```

#### 1.2 Storage Registration API

```php
test('Context register adds storage instance')
test('Context register uses storage prefix as key')
test('Context register tracks identity in IdentityStorage')
test('Context register dispatches StorageRegistered event')
test('Context register returns self for chaining')
test('Context make creates and registers storage from class')
test('Context make uses default drivers config when none provided')
test('Context make uses custom drivers config when provided')
```

#### 1.3 Storage Access API

```php
test('Context getStorage returns registered storage by prefix')
test('Context getStorage returns registered storage by class name')
test('Context getStorage returns null for unregistered storage')
test('Context has returns true for registered storage by prefix')
test('Context has returns true for registered storage by class name')
test('Context has returns false for unregistered storage')
test('Context getStorageNames returns all registered prefixes')
test('Context magic getter provides direct storage access')
test('Context magic getter returns null for unregistered storage')
```

#### 1.4 Bulk Operations

```php
test('Context save calls save on all registered storages')
test('Context save saves identity storage')
test('Context save only saves dirty storages')
test('Context save dispatches ContextSaving and ContextSaved events')
test('Context read calls read on all registered storages')
test('Context read dispatches ContextReading and ContextRead events')
test('Context clear clears all registered storages')
test('Context clear dispatches ContextClearing and ContextCleared events')
test('Context remove removes all storages from drivers')
test('Context remove clears identity storage')
```

#### 1.5 Key Tracking

```php
test('Context getTrackedKeys returns all storage keys from identity storage')
test('Context removeIdentityFromTracking removes key from identity storage')
test('Context getIdentityStorage returns the identity storage instance')
```

#### 1.6 Lifecycle & Destructor

```php
test('Context destructor auto-saves dirty storages')
test('Context lifecycle: register -> modify -> save persists correctly')
```

---

## Phase 2: IdentityStorage Tests (Priority: High)

### File: `tests/LarAgent/Context/IdentityStorageTest.php`

#### 2.1 Basic Operations

```php
test('IdentityStorage can be constructed')
test('IdentityStorage getStoragePrefix returns context')
test('IdentityStorage uses SessionIdentityArray as data model')
```

#### 2.2 Identity Management

```php
test('IdentityStorage addIdentity adds new identity')
test('IdentityStorage addIdentity does not duplicate existing keys')
test('IdentityStorage addIdentity marks storage as dirty')
test('IdentityStorage addIdentity dispatches IdentityAdding and IdentityAdded events')
test('IdentityStorage removeByKey removes identity by key')
test('IdentityStorage removeByKey marks storage as dirty')
test('IdentityStorage removeByKey does nothing for non-existent key')
test('IdentityStorage hasKey returns true for tracked key')
test('IdentityStorage hasKey returns false for untracked key')
test('IdentityStorage getByKey returns identity for tracked key')
test('IdentityStorage getByKey returns null for untracked key')
test('IdentityStorage getKeys returns all tracked keys')
test('IdentityStorage getIdentities returns SessionIdentityArray')
```

#### 2.3 Persistence

```php
test('IdentityStorage save dispatches events')
test('IdentityStorage save only saves when dirty')
test('IdentityStorage load dispatches IdentityStorageLoaded event')
test('IdentityStorage persists identities across instances')
```

---

## Phase 3: SessionIdentityArray Tests (Priority: Medium)

### File: `tests/LarAgent/Context/SessionIdentityArrayTest.php`

```php
test('SessionIdentityArray allows only SessionIdentity models')
test('SessionIdentityArray hasKey checks for key existence')
test('SessionIdentityArray getByKey returns identity or null')
test('SessionIdentityArray removeByKey removes by key')
test('SessionIdentityArray getKeys returns string array of keys')
test('SessionIdentityArray fromArray reconstructs identities')
test('SessionIdentityArray toArray serializes correctly')
```

---

## Phase 4: HasContext Trait Tests (Priority: Medium)

### File: `tests/LarAgent/Context/HasContextTraitTest.php`

```php
test('HasContext setChatSessionId sets userId when usesUserId is true')
test('HasContext setChatSessionId sets chatKey')
test('HasContext buildSessionId creates SessionIdentity')
test('HasContext setupContext creates Context instance')
test('HasContext context returns Context instance')
test('HasContext group can be set and retrieved')
test('HasContext usesUserId enables userId usage')
test('HasContext hasUserId returns correct status')
test('HasContext getChatKey returns chat key')
test('HasContext getUserId returns userId or null')
test('HasContext getAgentName returns agent name')
```

---

## Phase 5: Events Tests (Priority: Medium)

### File: `tests/LarAgent/Context/ContextEventsTest.php`

#### Context Events

```php
test('ContextCreated event contains context')
test('StorageRegistered event contains context, prefix, and storage')
test('ContextSaving event is dispatched before save')
test('ContextSaved event is dispatched after save')
test('ContextReading event is dispatched before read')
test('ContextRead event is dispatched after read')
test('ContextClearing event is dispatched before clear')
test('ContextCleared event is dispatched after clear')
```

#### IdentityStorage Events

```php
test('IdentityAdding event contains storage and identity')
test('IdentityAdded event contains storage and identity')
test('IdentityStorageSaving event contains storage and identities')
test('IdentityStorageSaved event contains storage')
test('IdentityStorageLoaded event contains storage and items')
```

---

## Phase 6: Integration Tests (Priority: Medium)

### File: `tests/LarAgent/Context/ContextIntegrationTest.php`

```php
test('Context with ChatHistoryStorage integration')
test('Context with multiple storage types')
test('Context with InMemoryStorage driver')
test('Context key isolation between different agents')
test('Context key isolation between different sessions')
test('Full lifecycle: create -> register -> add data -> save -> read')
test('Context recovery: save -> new instance -> read -> verify data')
```

---

## Phase 7: Config-based Tests (Priority: Low)

### File: `tests/LarAgent/Context/ConfigTest.php`

```php
test('Default storage drivers from config are used')
test('Default history storage drivers from config are used')
test('Custom drivers override default config')
```

---

## Implementation Order

1. **Phase 1**: Context Class Tests - Most critical, core functionality
2. **Phase 2**: IdentityStorage Tests - Key tracking is essential
3. **Phase 3**: SessionIdentityArray Tests - Data model for Phase 2
4. **Phase 4**: HasContext Trait Tests - Agent integration
5. **Phase 5**: Events Tests - Extensibility verification
6. **Phase 6**: Integration Tests - End-to-end scenarios
7. **Phase 7**: Config Tests - Edge cases and defaults

---

## Testing Utilities Needed

### Mock/Test Classes

```php
// TestStorage - already exists in StorageTest.php
// Can be moved to a shared Fakes directory for reuse

// MockSessionIdentity - already exists in StorageManagerTest.php
// Can be shared

// TestDataModel, TestDataModelArray - already exist
// Can be shared
```

### Shared Helpers

Consider creating a test helper file:

-   `createIdentity(string $agent, ?string $chat = null): SessionIdentity`
-   `createContext(SessionIdentity $identity, array $drivers = []): Context`
-   `createTestStorage(SessionIdentity $identity, array $drivers = []): TestStorage`

---

## Estimated Test Count

| Phase | File                         | Est. Tests | Actual |
| ----- | ---------------------------- | ---------- | ------ |
| 1     | ContextTest.php              | ~25 tests  | 43 ✅  |
| 2     | IdentityStorageTest.php      | ~15 tests  | 27 ✅  |
| 3     | SessionIdentityArrayTest.php | ~7 tests   | 28 ✅  |
| 4     | HasContextTraitTest.php      | ~11 tests  | 36 ✅  |
| 5     | ContextEventsTest.php        | ~13 tests  | 41 ✅  |
| 6     | ContextIntegrationTest.php   | ~7 tests   | 13 ✅  |
| 7     | ConfigTest.php               | ~3 tests   | 16 ✅  |

**Original Estimate: ~81 new tests**
**Actual Delivered: 204 new tests (251% of estimate)**

---

## Notes

### Test Isolation

-   Use `InMemoryStorage` as the primary driver in unit tests to avoid external dependencies
-   Mock Laravel facades (Event, Cache, etc.) where appropriate
-   Use `RefreshDatabase` trait only when testing Eloquent drivers

### Event Testing

Events can be tested using Laravel's `Event::fake()` or by checking if event classes exist and have correct properties.

### Avoiding Test Pollution

Each test should:

1. Create fresh instances
2. Not rely on state from previous tests
3. Clean up any side effects

---
