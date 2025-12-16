# Events Comparison: Old Agent/Engine Events vs New Native Events

This document compares the two event systems in LarAgent:
1. **Old Agent/Engine Events** - Have both overridable methods and Laravel event dispatch
2. **New Native Events** - Only dispatch Laravel events (no hook methods)

---

## Old Agent/Engine Events (with overridable methods)

These events can be handled in three ways:
1. Override the method in your Agent class
2. Register a callback via fluent API (e.g., `$agent->beforeSend(fn() => ...)`)
3. Listen to Laravel events

| Event Class | Hook Method | Callback Method |
|-------------|-------------|-----------------|
| `AgentInitialized` | `onInitialize()` | - |
| `ConversationStarted` | `onConversationStart()` | - |
| `ConversationEnded` | `onConversationEnd()` | - |
| `AgentCleared` | `onClear()` | - |
| `ToolChanged` | `onToolChange()` | - |
| `EngineError` | `onEngineError()` | - |
| `BeforeReinjectingInstructions` | `beforeReinjectingInstructions()` | `beforeReinjectingInstructions()` |
| `BeforeSend` | `beforeSend()` | `beforeSend()` |
| `AfterSend` | `afterSend()` | `afterSend()` |
| `BeforeSaveHistory` | `beforeSaveHistory()` | `beforeSaveHistory()` |
| `BeforeResponse` | `beforeResponse()` | `beforeResponse()` |
| `AfterResponse` | `afterResponse()` | `afterResponse()` |
| `BeforeToolExecution` | `beforeToolExecution()` | `beforeToolExecution()` |
| `AfterToolExecution` | `afterToolExecution()` | `afterToolExecution()` |
| `BeforeStructuredOutput` | `beforeStructuredOutput()` | `beforeStructuredOutput()` |

---

## New Native Events (Laravel events ONLY - no hook methods)

These events can only be handled via Laravel event listeners. They are dispatched from storage and context classes.

### ChatHistory Events

| Event | Purpose | Covered by old event? |
|-------|---------|----------------------|
| `MessageAdding` | Before message added to storage | ⚠️ Similar to `BeforeSend` |
| `MessageAdded` | After message added to storage | ⚠️ Similar to `AfterSend` |
| `ChatHistorySaving` | Before chat history save | ✅ `BeforeSaveHistory` |
| `ChatHistorySaved` | After chat history save | ❌ No equivalent |
| `ChatHistoryLoaded` | After chat history loaded | ❌ No equivalent |
| `ChatHistoryTruncated` | After truncation applied | ❌ No equivalent |

### Context Events

| Event | Purpose | Covered by old event? |
|-------|---------|----------------------|
| `ContextCreated` | After context initialized | ⚠️ Partially `onInitialize` |
| `ContextSaving` | Before context save | ❌ No equivalent |
| `ContextSaved` | After context save | ❌ No equivalent |
| `ContextReading` | Before context read | ❌ No equivalent |
| `ContextRead` | After context read | ❌ No equivalent |
| `ContextClearing` | Before context clear | ⚠️ Partially `onClear` |
| `ContextCleared` | After context clear | ⚠️ Partially `onClear` |
| `StorageRegistered` | After storage registered | ❌ No equivalent |

### IdentityStorage Events

| Event | Purpose | Covered by old event? |
|-------|---------|----------------------|
| `IdentityAdding` | Before identity added | ❌ No equivalent |
| `IdentityAdded` | After identity added | ❌ No equivalent |
| `IdentityStorageSaving` | Before identity storage save | ❌ No equivalent |
| `IdentityStorageSaved` | After identity storage save | ❌ No equivalent |
| `IdentityStorageLoaded` | After identity storage loaded | ❌ No equivalent |

---

## Key Differences

### Level of Operation
- **Old events**: Operate at the **Agent/Engine level** - they track the conversation flow and agent lifecycle
- **New events**: Operate at the **Storage/Context level** - they track data persistence and storage operations

### Timing
Even similar events fire at different points:
- `BeforeSend` fires before a message is sent to the LLM
- `MessageAdding` fires when a message is being added to storage (which may happen at different times)

### Data Payloads
- Old events receive `AgentDTO` plus relevant data
- New events receive the storage/context instance plus relevant data

---

## Summary

### Overlapping / Similar Events
| Old Event | New Event | Notes |
|-----------|-----------|-------|
| `BeforeSend` / `AfterSend` | `MessageAdding` / `MessageAdded` | Different timing & context |
| `BeforeSaveHistory` | `ChatHistorySaving` | Similar purpose |
| `onInitialize` | `ContextCreated` | Different scope |
| `onClear` | `ContextClearing` / `ContextCleared` | Different scope |

### Truly New Events (no old equivalent)
- `ChatHistorySaved` - After chat history persisted
- `ChatHistoryLoaded` - After chat history loaded from storage
- `ChatHistoryTruncated` - After truncation strategy applied
- All `IdentityStorage` events
- `ContextSaving`, `ContextSaved`, `ContextReading`, `ContextRead`
- `StorageRegistered`

---

## Future Consideration

The new native events currently only support Laravel event listeners. To maintain consistency with the old event system, we could:

1. **Option 1**: Add hook methods to storage classes (extend to customize)
2. **Option 2**: Forward storage events to Agent class hooks
3. **Option 3**: Keep as-is since old events cover most common use cases

The overlap between old and new events means most developers can use the existing hook methods for common scenarios, while the new Laravel-only events provide fine-grained control for advanced use cases.
