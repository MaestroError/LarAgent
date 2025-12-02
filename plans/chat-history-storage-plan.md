# Chat History Storage Implementation Plan (Phase 4+)

## Goals

-   Create `ChatHistoryStorage` extending `Storage` abstraction
-   Use Laravel Events for extensibility
-   Create `Usage` DataModel for token tracking on AssistantMessage
-   Keep metadata for intentional developer use only (not for usage data)

---

## Phase 4: Create Usage DataModel

### Purpose

A dedicated DataModel to store token usage information from LLM API responses. This replaces storing usage in metadata, making it a first-class property on AssistantMessage.

### Location

`src/Usage/DataModels/UsageData.php`

### Properties

| Property            | Type | Description                        |
| ------------------- | ---- | ---------------------------------- |
| `prompt_tokens`     | int  | Number of tokens in the prompt     |
| `completion_tokens` | int  | Number of tokens in the completion |
| `total_tokens`      | int  | Total tokens                       |

### Methods

-   `__construct(int $prompt_tokens = 0, int $completion_tokens = 0, int $total_tokens = 0)`
-   `toArray(): array` - Serialize to array
-   `fromArray(array $data): static` - Create from array (inherited from DataModel)

### Notes

-   Extends `DataModel` abstract class
-   Simple structure, no complex logic

---

## Phase 5: Update AssistantMessage with Usage

### Purpose

Add `usage` property to `AssistantMessage` as a first-class property using the `Usage` DataModel. This removes usage from metadata and makes it explicit.

### Location

`src/Messages/AssistantMessage.php`

### Changes

-   Add `public ?Usage $usage = null` property (excluded from schema)
-   Add `getUsage(): ?Usage` method
-   Add `setUsage(?Usage $usage): void` method
-   Update `toArray()` to include usage when present (excluded by default on serialization to API)
-   Update `fromArray()` to reconstruct Usage from array data

### Notes

-   Usage is excluded from schema (not sent to LLM API)
-   Usage is included in serialization for storage purposes
-   Drivers will set usage directly on the message instead of metadata
-   ToolCallMessage inherits from AssistantMessage, so it gets usage support automatically

---

## Phase 5.1: Update Drivers to Set Usage on Messages

### Purpose

Update all drivers to set usage directly on AssistantMessage/ToolCallMessage instead of passing it through metadata.

### Drivers to Update

-   `src/Drivers/OpenAi/BaseOpenAiDriver.php`
-   `src/Drivers/Groq/GroqDriver.php`
-   `src/Drivers/Gemini/GeminiDriver.php`
-   `src/Drivers/Anthropic/ClaudeDriver.php`
-   `tests/LarAgent/Fakes/FakeLlmDriver.php`

### Changes per Driver

1. Create `Usage` DataModel from extracted usage data
2. Call `$message->setUsage($usage)` instead of passing `['usage' => ...]` to constructor
3. Remove usage from metadata parameter in message constructors

### Notes

-   StreamedAssistantMessage already has `setUsage()` method - align it to use Usage DataModel
-   Handle usage keys inside driver, UsageData class should not include any logic, just pass correct keys

---

## Phase 6: Create ChatHistoryStorage

### Purpose

Specialized storage for chat messages. Focused on persistence and event hooks.

### Location

`src/Context/ChatHistoryStorage.php`

### Extends

`LarAgent\Context\Abstract\Storage`

### Constructor

```php
public function __construct(
    array $driversConfig,
    SessionIdentityContract $identity,
    bool $storeMeta = false  // Default to false - metadata stored only when explicitly enabled
)
```

### Key Methods (matching ChatHistory contract)

#### Message Operations

-   `addMessage(MessageInterface $message): void` - Add with event dispatch
-   `getMessages(): MessageArray` - Returns messages array
-   `getLastMessage(): ?MessageInterface`
-   `clear(): void` - Clear all messages
-   `count(): int` - Get message count

#### Serialization

-   `toArray(): array` - Messages as array (including usage data)
-   `toArrayWithMeta(): array` - Messages as array with metadata

#### Identifier

-   `getIdentifier(): string` - Returns identity key

#### Memory Operations (ChatHistory contract)

-   `readFromMemory(): void` - Force read from storage drivers (bypasses lazy loading)
-   `writeToMemory(): void` - Force write to storage drivers (bypasses dirty check)

#### Storage Operations (from Storage abstraction)

-   `save(): void` - Smart save with dirty tracking, event dispatch
-   `load(): void` - Lazy load from storage with event dispatch
-   `isDirty(): bool` - Check if changes need saving
-   `isLoaded(): bool` - Check if data has been loaded

### Configuration

-   `setStoreMeta(bool $store): void` - Enable/disable metadata storage
-   `shouldStoreMeta(): bool` - Check if metadata storage is enabled

### Abstract Methods from Storage

-   `getDataModelClass(): string` → returns `MessageArray::class`
-   `getStoragePrefix(): string` → returns `'chat_history'`

### Notes

-   `MessageFormatter` handles API formatting - not ChatHistoryStorage's responsibility
-   Contract methods return plain arrays for backward compatibility
-   Internal storage uses `MessageArray` for type safety
-   `save()`/`load()` are the main API; `readFromMemory()`/`writeToMemory()` are for direct driver access
-   Usage data is always stored (it's part of the message, not metadata)
-   Metadata storage is opt-in via constructor parameter
-   Avoid implementing current chat history contract

---

## Phase 7: Events

### Purpose

Enable extensibility through Laravel's event system instead of callbacks.

### Events to Create

| Event               | When Fired                 | Payload                 |
| ------------------- | -------------------------- | ----------------------- |
| `ChatHistoryLoaded` | After loading from storage | `$storage`, `$messages` |
| `ChatHistorySaving` | Before saving to storage   | `$storage`, `$messages` |
| `ChatHistorySaved`  | After saving to storage    | `$storage`              |
| `MessageAdding`     | Before adding message      | `$storage`, `$message`  |
| `MessageAdded`      | After adding message       | `$storage`, `$message`  |

### Location

`src/Events/ChatHistory/` directory

### Event Properties

Each event should have:

-   Public readonly properties for payload
-   Constructor for initialization
-   Implement appropriate interface if stoppable

---

## Phase 8: Testing

### Unit Tests

#### Usage DataModel Tests

Location: `tests/LarAgent/Messages/UsageTest.php`

-   Creates from constructor with defaults
-   Creates from array
-   Serializes to array correctly
-   Handles zero values

#### MessageArray Tests

Location: `tests/LarAgent/Messages/MessageArrayTest.php`

-   Reconstructs correct message types from discriminator
-   Handles nested discriminator (assistant → ToolCallMessage vs AssistantMessage)
-   Mixed message types round-trip correctly
-   AssistantMessage with usage round-trips correctly

#### AssistantMessage Tests

Location: `tests/LarAgent/Messages/AssistantMessageTest.php`

-   Creates with usage
-   Serializes usage in toArray()
-   Reconstructs usage from fromArray()
-   Usage excluded from schema

#### ChatHistoryStorage Tests

Location: `tests/LarAgent/Context/ChatHistoryStorageTest.php`

-   Returns MessageArray from getMessages()
-   Persists and loads messages correctly
-   Stores metadata when storeMeta is true
-   Does not store metadata when storeMeta is false (default)
-   Always stores usage data
-   Fires events at appropriate times

### Manual Tests

Location: `testsManual/ChatHistoryStorageTest.php`

-   Integration with real storage drivers
-   Integration with Agent class

---

## Implementation Order

1. 🔲 **Phase 4:** Create Usage DataModel
2. 🔲 **Phase 5:** Update AssistantMessage with Usage property
3. 🔲 **Phase 5.1:** Update Drivers to set Usage on messages
4. 🔲 **Phase 6:** Create ChatHistoryStorage (basic)
5. 🔲 **Phase 7:** Create Events
6. 🔲 **Phase 8:** Tests
7. 🔲 Update ChatHistoryStorage to dispatch events
8. 🔲 Update ChatHistory contract (add save/load methods)

---

## File Summary

### New Files

| File                                                | Purpose            |
| --------------------------------------------------- | ------------------ |
| `src/Messages/DataModels/Usage.php`                 | Usage DataModel    |
| `src/Context/ChatHistoryStorage.php`                | Main storage class |
| `src/Events/ChatHistory/ChatHistoryLoaded.php`      | Event              |
| `src/Events/ChatHistory/ChatHistorySaving.php`      | Event              |
| `src/Events/ChatHistory/ChatHistorySaved.php`       | Event              |
| `src/Events/ChatHistory/MessageAdding.php`          | Event              |
| `src/Events/ChatHistory/MessageAdded.php`           | Event              |
| `tests/LarAgent/Messages/UsageTest.php`             | Tests              |
| `tests/LarAgent/Messages/MessageArrayTest.php`      | Tests              |
| `tests/LarAgent/Messages/AssistantMessageTest.php`  | Tests              |
| `tests/LarAgent/Context/ChatHistoryStorageTest.php` | Tests              |

### Modified Files

| File                                        | Changes                        |
| ------------------------------------------- | ------------------------------ |
| `src/Messages/AssistantMessage.php`         | Add usage property and methods |
| `src/Messages/StreamedAssistantMessage.php` | Update to use Usage DataModel  |
| `src/Drivers/OpenAi/BaseOpenAiDriver.php`   | Set usage on message directly  |
| `src/Drivers/Groq/GroqDriver.php`           | Set usage on message directly  |
| `src/Drivers/Gemini/GeminiDriver.php`       | Set usage on message directly  |
| `src/Drivers/Anthropic/ClaudeDriver.php`    | Set usage on message directly  |
| `tests/LarAgent/Fakes/FakeLlmDriver.php`    | Set usage on message directly  |
| `src/Core/Contracts/ChatHistory.php`        | Add save/load methods          |
| `src/Core/Abstractions/ChatHistory.php`     | Add save/load methods          |

---

## Key Principles Adherence

### Ease of Use ✓

-   Usage is a first-class property on messages
-   `ChatHistoryStorage` works out of the box with sensible defaults
-   No complex configuration needed

### Flexibility ✓

-   `storeMeta` parameter allows enabling metadata storage when needed
-   Usage is always available on messages

### Ease of Extension ✓

-   Events for all important operations
-   Developers can add metadata when they need it

### Standardization ✓

-   Follows existing Storage abstraction pattern
-   Uses Laravel Events pattern
-   Consistent with existing LarAgent patterns
-   Usage DataModel follows existing DataModel pattern
