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
   - [Config File Updates](#10-config-file-updates)
4. [New Features (Non-Breaking)](#new-features-non-breaking)
5. [Laravel AI SDK Integration (New)](#laravel-ai-sdk-integration-new)
   - [Getting Started with the SDK Driver](#getting-started-with-the-sdk-driver)
   - [SDK Capability Tools](#sdk-capability-tools)
   - [Reverse SDK Compatibility](#reverse-sdk-compatibility)
   - [HookableDriver for Custom Drivers](#hookabledriver-for-custom-drivers)

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

### 10. Config File Updates

**Priority: MEDIUM** — Affects anyone who previously published `config/laragent.php`

#### What Changed

Several values and keys in the default config have changed since v0.8. If you published the config and haven't updated it, your file may have stale defaults.

| Item | v0.8 Value | v1.0 Value |
|------|-----------|-----------|
| `default_driver` | `\LarAgent\Drivers\OpenAi\OpenAiDriver::class` | `\LarAgent\Drivers\OpenAi\OpenAiCompatible::class` |
| `default.default_max_completion_tokens` | `100` | `10000` |
| Provider-level `chat_history` key | `chat_history` | `history` |
| Provider-level `default_context_window` key | `default_context_window` | `default_truncation_threshold` |

**New provider entries** added to the default config (you may want to add these):
- `gemini` — Gemini via OpenAI-compatible driver
- `gemini_native` — Gemini via native driver
- `groq` — Groq
- `claude` — Anthropic Claude
- `openrouter` — OpenRouter
- `ollama` — Ollama (local)
- `laravel-ai` — Laravel AI SDK driver (optional, PHP 8.4+/Laravel 12+)

**New top-level config keys:**
- `default_history_storage`, `default_storage` — default storage drivers
- `namespaces` — autodiscovery namespaces for `agent:chat`
- `default_providers` — global multi-provider fallback config
- `track_usage`, `default_usage_storage` — usage tracking
- `enable_truncation`, `truncation_provider`, `default_truncation_strategy`, `default_truncation_config`, `truncation_buffer` — truncation system
- `mcp_tool_caching`, `mcp_servers` — MCP server configuration

#### Migration Steps

**Option A:** Re-publish the config (back up customizations first):
```bash
cp config/laragent.php config/laragent.php.bak
php artisan vendor:publish --tag=laragent-config --force
```
Then manually re-apply your customizations from the backup.

**Option B:** Manually update your existing config:
```php
// 1. Update default_driver
'default_driver' => \LarAgent\Drivers\OpenAi\OpenAiCompatible::class,

// 2. Update default provider tokens
'default' => [
    // ...
    'default_max_completion_tokens' => 10000, // was 100
],

// 3. Rename keys in all provider entries
// 'chat_history' => ...    becomes    'history' => ...
// 'default_context_window' => ...    becomes    'default_truncation_threshold' => ...

// 4. (Optional) Add new provider entries — see config/laragent.php in the package source
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

## Laravel AI SDK Integration (New)

LarAgent v1.0 introduces optional integration with the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`). This is entirely additive — no existing code is affected. But it's a significant new capability worth understanding during your upgrade.

**Requirements:** PHP 8.4+, Laravel 12+, `composer require laravel/ai`

### Getting Started with the SDK Driver

The SDK integration lets you run your existing LarAgent agents through the SDK's Prism runtime, accessing any provider configured in `config/ai.php` without writing additional driver code.

**Step 1:** Install the SDK:
```bash
composer require laravel/ai
```

**Step 2:** The `laravel-ai` provider is already in the default config. If you published the config before this feature existed, add it:
```php
// config/laragent.php → providers array
'laravel-ai' => [
    'label' => 'openai',
    'driver' => \LarAgent\Drivers\LaravelAi\LaravelAiDriver::class,
    'sdk_provider' => 'openai', // Any provider configured in config/ai.php
    'default_truncation_threshold' => 50000,
    'default_max_completion_tokens' => 10000,
    'default_temperature' => 1,
],
```

**Step 3:** Point your agent at the SDK driver:
```php
class MyAgent extends Agent
{
    protected $provider = 'laravel-ai';
    protected $model = 'gpt-4o';

    // Everything else works unchanged — tools, events, hooks, chat history,
    // structured output, multi-provider fallback, streaming
}
```

**How it works:**

```
Agent::for('key')->respond('message')
  → Agent::respond()
    → LarAgent engine
      → LaravelAiDriver::sendMessage()
        → ConfigBridge maps provider labels to SDK names
        → MessageConverter converts LarAgent messages to SDK format
        → SdkToolBridge wraps LarAgent tools for the SDK
        → SDK agent() with bridged tools
          → Prism → provider API
          → SDK internal tool loop (LarAgent hooks fire via SdkToolBridge)
        ← SDK response
      ← MessageConverter extracts:
         - Final assistant response
         - Intermediate tool call/result messages for chat history
         - Usage data
    ← Chat history updated with all messages
```

**Key components:**

| Class | Location | Purpose |
|-------|----------|---------|
| `LaravelAiDriver` | `Drivers\LaravelAi\` | Orchestrates SDK calls (sync + streaming), delegates tool loop to SDK |
| `ConfigBridge` | `Drivers\LaravelAi\` | Maps LarAgent provider labels to SDK provider names (11 providers: OpenAI, Anthropic, Gemini, Groq, Ollama, Azure, xAI, DeepSeek, Mistral, Cohere, OpenRouter) |
| `MessageConverter` | `Drivers\LaravelAi\` | Bidirectional message conversion; extracts system instructions, reconstructs intermediate tool messages for chat history, maps usage data |
| `SdkToolBridge` | `Drivers\LaravelAi\` | Wraps LarAgent `Tool` objects as SDK `\Laravel\Ai\Contracts\Tool` implementations, firing `beforeToolExecution`/`afterToolExecution` hooks around each execution |

### SDK Capability Tools

Four ready-made tools expose SDK-specific capabilities to any LarAgent agent. These work with any driver but require `laravel/ai` to be installed:

```php
use LarAgent\Tools\LaravelAi\WebSearchTool;
use LarAgent\Tools\LaravelAi\EmbeddingTool;
use LarAgent\Tools\LaravelAi\ImageGenerationTool;
use LarAgent\Tools\LaravelAi\SimilaritySearchTool;

class ResearchAgent extends Agent
{
    protected $tools = [
        WebSearchTool::class,
        EmbeddingTool::class,
        ImageGenerationTool::class,
    ];

    // SimilaritySearchTool needs constructor args — register in boot()
    public static function boot()
    {
        parent::boot();
        static::registerTool(
            SimilaritySearchTool::usingModel(
                Document::class,
                'embedding',
                minSimilarity: 0.7,
                limit: 10
            )
        );
    }
}
```

| Tool | What it does | Configuration |
|------|-------------|---------------|
| `EmbeddingTool` | Generates vector embeddings via `Laravel\Ai\embed()` | `usingProvider($provider)`, `usingModel($model)` |
| `ImageGenerationTool` | Generates images via `Laravel\Ai\image()` | `usingProvider($provider)`, `usingModel($model)` |
| `SimilaritySearchTool` | Vector similarity search via SDK's `SimilaritySearch` | Constructor: model class, column, min similarity, limit, query callback. Factory: `usingModel(...)` |
| `WebSearchTool` | Web search via SDK's `WebSearch` provider tool | `max($count)`, `allow($domains)`, `block($domains)` |

### Reverse SDK Compatibility

The `ImplementsSdkAgent` trait makes your LarAgent agents usable anywhere the Laravel AI SDK expects an Agent:

```php
use LarAgent\Concerns\ImplementsSdkAgent;

class MyAgent extends Agent implements \Laravel\Ai\Contracts\Agent
{
    use ImplementsSdkAgent;

    // Your existing agent code — no changes needed
}

// Now usable in SDK contexts:
$response = (new MyAgent)->prompt('Hello!');
```

The trait bridges three SDK contract methods:
- `instructions()` — returns the agent's system instructions
- `tools()` — wraps registered tools via `SdkToolBridge`
- `messages()` — converts chat history via `MessageConverter`

### Unified Storage & Context Integration (New in v1.0)

The SDK driver now shares LarAgent's storage, truncation, usage tracking, session identity, and event system. If you were previously using the SDK driver, the following new config keys are available under the `laravel-ai` provider in `config/laragent.php`:

```php
'laravel-ai' => [
    // ... existing config ...

    // Unified conversation storage (new)
    // null = auto-register LarAgentConversationStore (recommended)
    // false = disabled (SDK uses its own storage)
    // class = custom ConversationStore implementation
    'conversation_store' => null,

    // Bridge SDK events to LarAgent events (new)
    // true = SDK PromptingAgent/AgentPrompted events fire LarAgent BeforeSend/AfterResponse
    // false = disabled
    'bridge_events' => true,
],
```

**What this means for you:**

- **Truncation works**: SDK intermediate messages (tool call/result pairs) now carry accurate per-step usage data. The truncation engine sees cumulative token costs and triggers correctly.
- **Single storage**: The SDK's `RememberConversation` middleware writes through `LarAgentConversationStore` into LarAgent's storage drivers (Cache, Eloquent, File, etc.) instead of maintaining a separate persistence layer.
- **Session identity flows**: LarAgent's `SessionIdentity` is automatically passed to the SDK driver, ensuring consistent storage keys across both systems.
- **Unified events**: SDK events bridge to LarAgent events, so your existing event listeners and observers work regardless of which driver is active.

**No migration required** — these features activate automatically. The new config keys have sensible defaults (`conversation_store: null`, `bridge_events: true`).

---

### HookableDriver for Custom Drivers

If you maintain a custom driver that handles tool execution internally (like `LaravelAiDriver` does), you can implement the new `HookableDriver` interface so LarAgent's hook/event system still fires during the driver's internal tool loop:

```php
use Closure;
use LarAgent\Core\Contracts\HookableDriver;

class MyCustomSdkDriver extends LlmDriver implements HookableDriver
{
    protected ?Closure $beforeToolHook = null;
    protected ?Closure $afterToolHook = null;

    public function setHookCallbacks(?Closure $before, ?Closure $after): static
    {
        $this->beforeToolHook = $before;
        $this->afterToolHook = $after;
        return $this;
    }

    // In your internal tool execution loop:
    protected function executeTool($tool, $args)
    {
        // Fire the before hook — return false to cancel
        if ($this->beforeToolHook && ($this->beforeToolHook)($tool, $args) === false) {
            return json_encode(['error' => 'Tool execution cancelled by hook.']);
        }

        $result = $tool->execute($args);

        // Fire the after hook
        if ($this->afterToolHook) {
            ($this->afterToolHook)($tool, $args, $result);
        }

        return $result;
    }
}
```

This is **optional** — only needed when your driver manages its own tool loop. Standard drivers where the `LarAgent` engine manages the loop do not need this.

The `Agent` class automatically detects `HookableDriver` implementations and passes the hook callbacks:

```php
// From Agent.php — happens automatically
if ($this->llmDriver instanceof HookableDriver) {
    $this->llmDriver->setHookCallbacks(
        before: fn ($tool, $args) => $this->callEvent('beforeToolExecution', [$tool, $args]),
        after: fn ($tool, $args, $result) => $this->callEvent('afterToolExecution', [$tool, $args, $result]),
    );
}
```

---

## Quick Migration Checklist

**Breaking changes:**
- [ ] Replace all `Message::create()` with typed factory methods
- [ ] Replace all `Message::fromArray()` with specific message class `fromArray()`
- [ ] Update `ToolResultMessage` constructor calls to include `$toolName`
- [ ] Update `ToolCallMessage` constructor calls to remove `$message` parameter
- [ ] Replace `$contextWindowSize` with `$truncationThreshold`
- [ ] Remove `$saveChatKeys` (now automatic via Context system)
- [ ] Remove `$includeModelInChatSessionId` and related method calls

**Config updates:**
- [ ] Update `default_driver` to `OpenAiCompatible::class` (if published)
- [ ] Update `default_max_completion_tokens` from `100` to `10000` (if published)
- [ ] Rename provider config key `default_context_window` → `default_truncation_threshold`
- [ ] Rename provider config key `chat_history` → `history`
- [ ] Add new provider entries (gemini, claude, groq, openrouter, ollama, laravel-ai) or re-publish

**Custom drivers (if applicable):**
- [ ] Update constructor to accept `DriverConfig|array` and call `parent::__construct($settings)`
- [ ] Implement `MessageFormatter` (deprecated driver methods still work for now)
- [ ] (Optional) Implement `HookableDriver` if your driver manages its own tool loop

**Custom chat history (if applicable):**
- [ ] Refactor to use `ChatHistoryStorage` with custom `StorageDriver`

**Verification:**
- [ ] Run `composer test` to confirm everything passes
- [ ] Run `grep -r "Message::create\|Message::fromArray\|chat_history" app/` to catch stragglers

**Optional new features:**
- [ ] Install `laravel/ai` for SDK integration (PHP 8.4+, Laravel 12+)
- [ ] Try SDK capability tools (EmbeddingTool, ImageGenerationTool, etc.)
- [ ] Add `ImplementsSdkAgent` trait for reverse SDK compatibility

---

## Getting Help

If you encounter issues during migration:

1. Check the [CHANGELOG.md](CHANGELOG.md) for detailed change notes
2. Open an issue on [GitHub](https://github.com/MaestroError/LarAgent/issues)
3. Review the test files for usage examples
