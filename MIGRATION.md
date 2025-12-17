# Migration Guide: v0.8 to v1.0

This document provides a comprehensive guide for migrating your LarAgent project from v0.8 to v1.0. The guide is organized by priority, starting with the most critical changes that affect the public API.

---

## Table of Contents

1. [Critical Changes](#critical-changes)
   - [Message Factory API Changes](#1-message-factory-api-changes)
   - [ToolResultMessage Constructor Signature](#2-toolresultmessage-constructor-signature)
   - [ToolCallMessage Constructor Signature](#3-toolcallmessage-constructor-signature)
   - [ChatHistory Interface Changes](#4-chathistory-interface-changes)
2. [Agent Class Changes](#agent-class-changes)
   - [Removed Properties and Methods](#5-removed-agent-properties-and-methods)
   - [New Context System](#6-new-context-system)
   - [Config Property Renames](#7-config-property-renames)
3. [Driver and Configuration Changes](#driver-and-configuration-changes)
   - [DriverConfig DTO](#8-driverconfig-dto-for-custom-drivers)
   - [Custom ChatHistory Migration](#9-custom-chathistory-implementations)
4. [New Features (Non-Breaking)](#new-features-non-breaking)

---

## Critical Changes

### 1. Message Factory API Changes

**Priority: HIGH** - Affects all code using `Message::create()` or `Message::fromArray()`

#### What Changed

The `Message` class is now a **pure factory class** with only typed static factory methods. The following methods have been **removed**:

- `Message::create()` ❌ REMOVED
- `Message::fromArray()` ❌ REMOVED  
- `Message::fromJSON()` ❌ REMOVED

#### Migration Steps

**Step 1:** Find all occurrences of `Message::create()` in your codebase:
```bash
grep -rn "Message::create" --include="*.php"
```

**Step 2:** Replace with typed factory methods:

```php
// ❌ BEFORE (v0.8)
$message = Message::create('user', 'Hello');
$message = Message::create('assistant', 'Hi there');
$message = Message::create('system', 'You are helpful');

// ✅ AFTER (v1.0)
$message = Message::user('Hello');
$message = Message::assistant('Hi there');
$message = Message::system('You are helpful');
```

**Step 3:** Find and replace `Message::fromArray()`:
```bash
grep -rn "Message::fromArray" --include="*.php"
```

```php
// ❌ BEFORE (v0.8)
$message = Message::fromArray(['role' => 'user', 'content' => 'Hello']);

// ✅ AFTER (v1.0)
// Use the specific message class:
$message = UserMessage::fromArray(['role' => 'user', 'content' => 'Hello']);
// Or use MessageArray for collections:
$messages = MessageArray::fromArray($arrayOfMessages);
```

**Step 4:** Find and replace `Message::fromJSON()`:
```bash
grep -rn "Message::fromJSON" --include="*.php"
```

```php
// ❌ BEFORE (v0.8)
$message = Message::fromJSON($jsonString);

// ✅ AFTER (v1.0)
$data = json_decode($jsonString, true);
$message = UserMessage::fromArray($data); // or appropriate message class
```

#### Available Factory Methods

| Method | Description |
|--------|-------------|
| `Message::user($content, $metadata)` | Create user message |
| `Message::assistant($content, $metadata)` | Create assistant message |
| `Message::system($content, $metadata)` | Create system message |
| `Message::developer($content, $metadata)` | Create developer message |
| `Message::toolCall($toolCalls, $metadata)` | Create tool call message |
| `Message::toolResult($content, $toolCallId, $toolName, $metadata)` | Create tool result message |

---

### 2. ToolResultMessage Constructor Signature

**Priority: HIGH** - Affects all manual tool result message creation

#### What Changed

The `ToolResultMessage` constructor now accepts `$toolName` as an optional third parameter. While it has a default value (empty string), you should explicitly provide it for Gemini driver compatibility.

#### Migration Steps

**Step 1:** Find all `new ToolResultMessage(` occurrences:
```bash
grep -rn "new ToolResultMessage" --include="*.php"
```

**Step 2:** Update the constructor calls:

```php
// ❌ BEFORE (v0.8)
new ToolResultMessage($resultArray, $metadata);
// or
new ToolResultMessage(['content' => $result, 'tool_call_id' => $id], $metadata);

// ✅ AFTER (v1.0)
new ToolResultMessage($content, $toolCallId, $toolName, $metadata);
// or use the factory method (recommended):
Message::toolResult($content, $toolCallId, $toolName, $metadata);
```

**Constructor Signature Change:**
```php
// v0.8
public function __construct(array $message, array $metadata = [])

// v1.0
public function __construct(
    ToolResultContent|string $content,
    string $toolCallId,
    string $toolName = '',
    array $metadata = []
)
```

---

### 3. ToolCallMessage Constructor Signature

**Priority: HIGH** - Affects all manual tool call message creation

#### What Changed

The `ToolCallMessage` constructor no longer accepts the `$message` array parameter.

#### Migration Steps

**Step 1:** Find all `new ToolCallMessage(` occurrences:
```bash
grep -rn "new ToolCallMessage" --include="*.php"
```

**Step 2:** Update the constructor calls:

```php
// ❌ BEFORE (v0.8)
new ToolCallMessage($toolCalls, $messageArray, $metadata);
// $messageArray was typically the raw API response array

// ✅ AFTER (v1.0)
new ToolCallMessage($toolCalls, $metadata);
// or use the factory method (recommended):
Message::toolCall($toolCalls, $metadata);
```

**Constructor Signature Change:**
```php
// v0.8
public function __construct(array $toolCalls, array $message, array $metadata = [])

// v1.0
public function __construct(ToolCallArray|array $toolCalls, array $metadata = [])
```

---

### 4. ChatHistory Interface Changes

**Priority: HIGH** - Affects custom ChatHistory implementations and direct ChatHistory usage

#### What Changed

1. `getMessages()` now returns `MessageArray` instead of `array`
2. Methods removed from interface:
   - `setContextWindow(int $tokens)` ❌ REMOVED
   - `exceedsContextWindow(int $tokens)` ❌ REMOVED
   - `truncateOldMessages(int $messagesCount)` ❌ REMOVED
   - `saveKeyToMemory()` ❌ REMOVED
   - `loadKeysFromMemory()` ❌ REMOVED
   - `removeChatFromMemory(string $key)` ❌ REMOVED

3. New truncation system replaces manual context window management

#### Migration Steps

**Step 1:** Update code using `getMessages()`:

```php
// ❌ BEFORE (v0.8) - returned array
$messages = $chatHistory->getMessages(); // array
foreach ($messages as $msg) { ... }

// ✅ AFTER (v1.0) - returns MessageArray
$messages = $chatHistory->getMessages(); // MessageArray (implements Countable, IteratorAggregate)
foreach ($messages as $msg) { ... }  // Still iterable
count($messages);                     // Still countable
$messages->all();                     // Get as array if needed
$messages->first();                   // Get first message
$messages->last();                    // Get last message
```

**Step 2:** Replace context window methods with truncation configuration:

```php
// ❌ BEFORE (v0.8)
$chatHistory->setContextWindow(50000);
if ($chatHistory->exceedsContextWindow($tokens)) {
    $chatHistory->truncateOldMessages(5);
}

// ✅ AFTER (v1.0)
// Configure truncation in Agent class or config:
protected $enableTruncation = true;
protected $truncationThreshold = 50000;
// Truncation is handled automatically by the truncation strategy
```

**Step 3:** Replace chat key management:

```php
// ❌ BEFORE (v0.8)
$chatHistory->saveKeyToMemory();
$keys = $chatHistory->loadKeysFromMemory();
$chatHistory->removeChatFromMemory($key);

// ✅ AFTER (v1.0)
// Use the new Context facade or Agent methods:
use LarAgent\Facades\Context;
use App\AiAgents\MyAgent;

// Option 1: Inside Agent - get chat keys via Agent methods
$agent = MyAgent::for('user-123');
$chatKeys = $agent->getChatKeys();           // Get all chat history keys for this agent
$allStorageKeys = $agent->getStorageKeys();  // Get all storage keys (including usage, state, etc.)
$chatIdentities = $agent->getChatIdentities(); // Get chat identities as SessionIdentityArray

// Option 2: Inside Agent - get current session key
$sessionKey = $this->context()->getIdentity()->getKey(); // Full storage key
$chatName = $this->context()->getIdentity()->getChatName(); // Chat name portion

// Option 3: Outside Agent - use Context facade with agent class
$chatKeys = Context::of(MyAgent::class)->getChatKeys();
$storageKeys = Context::of(MyAgent::class)->getStorageKeys();

// Option 4: Outside Agent - use Context facade with agent name (lightweight)
$chatKeys = Context::named('MyAgent')->getChatKeys();
$storageKeys = Context::named('MyAgent')->getStorageKeys();

// Clear/remove chat histories
Context::of(MyAgent::class)->clearAllChats();                    // Clear all chats (keeps keys tracked)
Context::of(MyAgent::class)->removeAllChats();                   // Remove all chats (deletes entirely)
Context::of(MyAgent::class)->forUser('user-123')->clear();       // Clear chats for specific user
Context::of(MyAgent::class)->forChat('support')->remove();       // Remove specific chat

// Filter and iterate over identities
Context::of(MyAgent::class)
    ->forStorage(ChatHistoryStorage::class)
    ->forUser('user-123')
    ->each(fn($identity, $agent) => $agent->chatHistory()->clear());

// Get identities with filters
$identities = Context::of(MyAgent::class)->getIdentities();        // All tracked identities
$userChats = Context::of(MyAgent::class)->forUser('user-123')->getChatIdentities();
```

---

## Agent Class Changes

### 5. Removed Agent Properties and Methods

**Priority: MEDIUM** - Affects Agents using these deprecated features

#### Removed Properties

| Property | Replacement |
|----------|-------------|
| `$includeModelInChatSessionId` | Removed - not supported |
| `$saveChatKeys` | Automatic - handled by new Context system's `IdentityStorage` |
| `$contextWindowSize` | Use `$truncationThreshold` |

#### Removed Methods

| Method | Replacement |
|--------|-------------|
| `keyIncludesModelName()` | Removed - not supported |
| `withModelInChatSessionId()` | Removed - not supported |
| `withoutModelInChatSessionId()` | Removed - not supported |
| `createChatHistory(string $sessionId)` | `createChatHistory()` (no parameter) |

#### Renamed Methods

The following methods have been renamed for clarity (old names still work but are deprecated):

| Old Method (v0.8) | New Method (v1.0) | Returns | Description |
|-------------------|-------------------|---------|-------------|
| `getChatSessionId()` | `getSessionId()` | `string` | Full storage key (e.g., `AgentName_chatHistory_user-123`) |
| `getChatKey()` | `getSessionKey()` | `string` | Session key passed to `for()` (e.g., `user-123`) |

#### Available Methods

These methods from `HasContext` trait are available:

| Method | Returns | Description |
|--------|---------|-------------|
| `getSessionId()` | `string` | Full storage key (e.g., `AgentName_chatHistory_user-123`) |
| `getSessionKey()` | `string` | Session key passed to `for()` (e.g., `user-123`) |
| `getUserId()` | `?string` | User ID if `usesUserId()` was called |
| `getAgentName()` | `string` | Agent class name |
| `group()` | `?string` | Group name if set |
| `context()` | `Context` | Access to context object |

#### Migration Steps

**Step 1:** Update method calls (recommended but not required - old names still work):
```php
// ❌ BEFORE (v0.8) - deprecated, still works
$fullKey = $this->getChatSessionId();
$chatKey = $this->getChatKey();

// ✅ AFTER (v1.0) - new method names
$fullKey = $this->getSessionId();     // Full storage key: "AgentName_chatHistory_user-123"
$sessionKey = $this->getSessionKey(); // Session key portion: "user-123"
$userId = $this->getUserId();         // User ID if using forUser()
$agentName = $this->getAgentName();   // Agent class name

// ✅ You can also access via context identity:
$identity = $this->context()->getIdentity();
$fullKey = $identity->getKey();          // Same as getSessionId()
$chatName = $identity->getChatName();    // Same as getSessionKey()
$userId = $identity->getUserId();        // Same as getUserId()
$agentName = $identity->getAgentName();  // Same as getAgentName()
```

**Step 2:** Remove `includeModelInChatSessionId` usage:
```php
// ❌ BEFORE (v0.8)
protected $includeModelInChatSessionId = true;
// and
$agent->withModelInChatSessionId();
$agent->withoutModelInChatSessionId();

// ✅ AFTER (v1.0)
// This feature has been removed. If you need model-specific sessions,
// include the model in your chat key manually:
YourAgent::for($userId . '-' . $model);
```

**Step 3:** Replace `contextWindowSize` with `truncationThreshold`:
```php
// ❌ BEFORE (v0.8)
protected $contextWindowSize = 50000;

// ✅ AFTER (v1.0)
protected $truncationThreshold = 50000;
protected $enableTruncation = true;  // Must enable truncation
```

---

### 6. New Context System

**Priority: MEDIUM** - New architecture for storage management

#### What Changed

LarAgent v1.0 introduces a new **Context System** that manages all storages (chat history, state, identities) through a unified interface.

#### New Properties

| Property | Type | Description |
|----------|------|-------------|
| `$storage` | `array` | Default storage drivers for context |
| `$forceReadHistory` | `bool` | Force read history on construction |
| `$forceSaveHistory` | `bool` | Force save history after each response |
| `$forceReadContext` | `bool` | Force read context on construction |
| `$trackUsage` | `bool` | Enable token usage tracking |
| `$usageStorage` | `array` | Storage drivers for usage data |
| `$enableTruncation` | `bool` | Enable automatic truncation |

#### Migration Steps

**Step 1:** Access chat history through context:
```php
// ❌ BEFORE (v0.8)
$this->chatHistory->addMessage($message);

// ✅ AFTER (v1.0)
$this->chatHistory()->addMessage($message);
// or
$this->context()->getStorage(ChatHistoryStorage::class)->addMessage($message);
```

**Step 2:** Configure storage drivers:
```php
// In your Agent class:
protected $storage = [
    \LarAgent\Context\Drivers\CacheStorage::class,
];

// Or use the built-in history drivers:
protected $history = 'cache';  // Still works
```

---

### 7. Config Property Renames

**Priority: MEDIUM** - Affects provider configuration

#### In Config File (`config/laragent.php`)

| v0.8 Key | v1.0 Key |
|----------|----------|
| `default_context_window` | `default_truncation_threshold` |
| `chat_history` (in provider) | `history` |

#### Migration Steps

**Step 1:** Update your published config file:
```php
// ❌ BEFORE (v0.8)
'providers' => [
    'default' => [
        // ...
        'default_context_window' => 50000,
        'chat_history' => \LarAgent\History\CacheChatHistory::class,
    ],
],

// ✅ AFTER (v1.0)
'providers' => [
    'default' => [
        // ...
        'default_truncation_threshold' => 50000,
        'history' => \LarAgent\History\CacheChatHistory::class,
    ],
],
```

**Or:** If using `php artisan vendor:publish`, republish the config:
```bash
php artisan vendor:publish --tag=laragent-config --force
```

---

## Driver and Configuration Changes

### 8. DriverConfig DTO for Custom Drivers

**Priority: LOW** - Only affects custom LLM driver implementations

#### What Changed

Driver configurations now use `DriverConfig` DTO internally instead of plain arrays.

#### Migration Steps (for custom drivers only)

**Step 1:** Update constructor signature:
```php
// ❌ BEFORE (v0.8)
class MyCustomDriver extends LlmDriver
{
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }
}

// ✅ AFTER (v1.0)
use LarAgent\Core\DTO\DriverConfig;

class MyCustomDriver extends LlmDriver
{
    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings); // Required!
        // Custom initialization here
    }
}
```

**Step 2:** Update config access:
```php
// ❌ BEFORE (v0.8)
$model = $this->settings['model'];
$apiKey = $this->settings['api_key'];

// ✅ AFTER (v1.0) - Array access still works
$model = $this->getSettings()['model'];

// ✅ AFTER (v1.0) - Typed access (recommended)
$model = $this->getDriverConfig()->model;
$apiKey = $this->getDriverConfig()->apiKey;
```

**Step 3:** Update `sendMessage` and `sendMessageStreamed` signatures:
```php
// ❌ BEFORE (v0.8)
public function sendMessage(array $messages, array $options = []): AssistantMessage

// ✅ AFTER (v1.0)
public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
```

---

### 9. Custom ChatHistory Implementations

**Priority: LOW** - Only affects custom ChatHistory classes

#### What Changed

Chat history classes should now extend `ChatHistoryStorage` instead of the old `ChatHistory` abstract class.

#### Migration Steps

**Step 1:** Update your custom history class:

```php
// ❌ BEFORE (v0.8)
use LarAgent\Core\Abstractions\ChatHistory;

class MyCustomHistory extends ChatHistory
{
    public function readFromMemory(): void { /* ... */ }
    public function writeToMemory(): void { /* ... */ }
    public function saveKeyToMemory(): void { /* ... */ }
    public function loadKeysFromMemory(): array { /* ... */ }
    public function removeChatFromMemory(string $key): void { /* ... */ }
    protected function removeChatKey(string $key): void { /* ... */ }
}

// ✅ AFTER (v1.0)
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Drivers\YourCustomStorageDriver;

class MyCustomHistory extends ChatHistoryStorage
{
    protected array $defaultDrivers = [YourCustomStorageDriver::class];
}
```

**Step 2:** If you need custom storage logic, create a custom StorageDriver:

```php
use LarAgent\Context\Contracts\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class MyStorageDriver implements StorageDriver
{
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        // Your read logic
    }
    
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        // Your write logic
    }
    
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        // Your remove logic
    }
    
    public static function make(?array $config = null): static
    {
        return new static();
    }
}
```

---

## New Features (Non-Breaking)

These features are new in v1.0 and don't require migration, but you may want to use them:

### Message IDs and Timestamps

All messages now have unique IDs and timestamps:
```php
$message = Message::user('Hello');
$id = $message->getId();           // e.g., 'msg_abc123...'
$created = $message->getCreatedAt(); // ISO 8601 timestamp
```

### Message Extras

Store driver-specific or custom fields:
```php
$message->setExtra('custom_field', 'value');
$value = $message->getExtra('custom_field');
$all = $message->getExtras();
```

### Usage Tracking

Enable automatic token usage tracking:
```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
}
```

### Truncation Strategies

Automatic conversation truncation when context exceeds threshold:
```php
class MyAgent extends Agent
{
    protected $enableTruncation = true;
    protected $truncationThreshold = 50000;
    
    // Override to use custom strategy
    protected function truncationStrategy()
    {
        return new SummarizationStrategy();
    }
}
```

### Context Facade

New facade for managing storage outside of agents:
```php
use LarAgent\Facades\Context;
use LarAgent\Context\Storages\ChatHistoryStorage;
use App\AiAgents\MyAgent;

// ========================================
// Entry Point 1: Context::of(AgentClass::class)
// Full agent-based context access (creates temporary agent)
// ========================================

// Get all chat keys for an agent
$chatKeys = Context::of(MyAgent::class)->getChatKeys();
$storageKeys = Context::of(MyAgent::class)->getStorageKeys();

// Fluent filtering API
$count = Context::of(MyAgent::class)
    ->forUser('user-123')
    ->forStorage(ChatHistoryStorage::class)
    ->count();

// Iterate with full agent access
Context::of(MyAgent::class)
    ->forUser('user-123')
    ->each(function ($identity, $agent) {
        // $identity is SessionIdentityContract
        // $agent is fully initialized Agent instance
        $agent->chatHistory()->clear();
    });

// Clear/remove operations
Context::of(MyAgent::class)->clearAllChats();           // Clear all chat data
Context::of(MyAgent::class)->removeAllChats();          // Remove all chat entries
Context::of(MyAgent::class)->clearAllChatsByUser('user-123');
Context::of(MyAgent::class)->removeAllChatsByUser('user-123');

// Get first matching agent
$agent = Context::of(MyAgent::class)->forUser('user-123')->firstAgent();

// ========================================
// Entry Point 2: Context::named('AgentName')
// Lightweight access (no agent initialization)
// ========================================

// Get keys without creating agent instances
$chatKeys = Context::named('MyAgent')->getChatKeys();
$storageKeys = Context::named('MyAgent')->getStorageKeys();

// Custom driver configuration
$count = Context::named('MyAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->count();

// Clear chats (lightweight - no agent needed)
Context::named('MyAgent')->clearAllChats();
Context::named('MyAgent')->removeAllChats();
```

### DataModel Classes

Use DataModels for structured output:
```php
class MyResponse extends DataModel
{
    #[Desc('The answer to the question')]
    public string $answer;
    
    #[Desc('Confidence level 0-100')]
    public int $confidence;
}

class MyAgent extends Agent
{
    protected $responseSchema = MyResponse::class;
}

// Response is automatically reconstructed as MyResponse instance
$response = MyAgent::ask('What is 2+2?');
$response->answer;     // "4"
$response->confidence; // 95
```

---

## Quick Migration Checklist

- [ ] Replace all `Message::create()` with typed factory methods
- [ ] Replace all `Message::fromArray()` with specific message class `fromArray()`
- [ ] Update `ToolResultMessage` constructor calls to include `$toolName`
- [ ] Update `ToolCallMessage` constructor calls to remove `$message` parameter
- [ ] Replace `$contextWindowSize` with `$truncationThreshold`
- [ ] Remove `$saveChatKeys` (now automatic via Context system)
- [ ] Remove `$includeModelInChatSessionId` and related method calls
- [ ] Update provider config `default_context_window` → `default_truncation_threshold`
- [ ] Update provider config `chat_history` → `history`
- [ ] If custom drivers: update constructor to call `parent::__construct($settings)`
- [ ] If custom chat history: refactor to use `ChatHistoryStorage` with custom driver

---

## Getting Help

If you encounter issues during migration:

1. Check the [CHANGELOG.md](CHANGELOG.md) for detailed change notes
2. Open an issue on [GitHub](https://github.com/MaestroError/LarAgent/issues)
3. Review the test files for usage examples
