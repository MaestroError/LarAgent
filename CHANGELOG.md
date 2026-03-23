# Changelog

All notable changes to `LarAgent` will be documented in this file.

## [v1.0] - Unreleased

### Anthropic Driver: Structured Output Improvements

-   **Strict tool use enabled by default**: All tool definitions now include `strict: true` and `additionalProperties: false` on `input_schema`, enabling guaranteed schema validation on tool names and inputs. This ensures Claude always returns correctly-typed tool parameters, eliminating the need for validation retries.
-   **`$defs`/`definitions` support in schema processing**: The `ensureAdditionalPropertiesFalse` method now recursively processes `$defs` and `definitions` blocks in JSON schemas, ensuring schemas using `$ref` references are fully compliant with Claude's structured output requirements.
-   **`refusal` stop reason handling**: The driver now handles Claude's `refusal` stop reason (returned when Claude declines a request for safety reasons). A descriptive exception is thrown instead of an "Unexpected stop reason" error.

### ⚠️ Breaking Changes (v0.8 → v1.0)

#### Provider config key

Provider config key "chat_history" replaced with "history"

#### Model name in chat session ID

Deprecated Agent property `includeModelInChatSessionId` and all related methods:

`withoutModelInChatSessionId`
`withModelInChatSessionId`

#### 6. DriverConfig DTO Replaces Array-Based Configuration

**What Changed:**

-   Driver configurations now use `DriverConfig` DTO internally instead of plain arrays
-   `LlmDriver` constructor now accepts `DriverConfig|array` (backward compatible)
-   `LlmDriver::sendMessage()` and `sendMessageStreamed()` accept `DriverConfig|array` for override settings
-   Configuration property names use camelCase: `apiKey`, `apiUrl`, `maxCompletionTokens`, etc.
-   Provider configs in `config/laragent.php` still use snake_case (`api_key`, `api_url`) - mapped automatically

**Migration Required for Custom Drivers:**

If you have custom LLM drivers extending `LlmDriver`, update the constructor:

```php
// Before (v0.8)
class MyCustomDriver extends LlmDriver
{
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        // custom initialization
    }
}

// After (v1.0)
use LarAgent\Core\DTO\DriverConfig;

class MyCustomDriver extends LlmDriver
{
    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings); // Required - initializes $this->driverConfig
        // custom initialization
    }
}
```

**Accessing Configuration in Custom Drivers:**

```php
// Array access (still works - backward compatible)
$model = $this->getSettings()['model'];
$apiKey = $this->getSettings()['apiKey'];

// Typed access (new, recommended)
$model = $this->getDriverConfig()->model;
$apiKey = $this->getDriverConfig()->apiKey;
$temperature = $this->getDriverConfig()->temperature;

// Check if property is set
if ($this->getDriverConfig()->has('temperature')) {
    // ...
}

// Get with default
$temp = $this->getDriverConfig()->get('temperature', 0.7);

// Access extra configs
$custom = $this->getDriverConfig()->getExtra('customOption');
```

**No Changes Required If:**

-   You're only using built-in drivers (OpenAI, Claude, Gemini, Groq)
-   You're using the Agent class API normally

#### 1. Message::create() and Message::fromArray() Removed

**What Changed:**

-   `Message::create()` static method removed
-   `Message::fromArray()` static method removed
-   `Message` class is now a pure factory with only typed factory methods

**Migration Required:**

```php
// Before (v0.8)
$message = Message::create('user', 'Hello');
$message = Message::fromArray(['role' => 'user', 'content' => 'Hello']);

// After (v1.0)
$message = Message::user('Hello');
$message = Message::assistant('Hi there');
$message = Message::system('You are helpful');
$message = Message::toolResult($content, $toolCallId, $toolName);
```

#### 2. ToolResultMessage Constructor Signature Changed

**What Changed:**

-   `ToolResultMessage` constructor now requires `toolName` as third parameter
-   Required for Gemini driver's `function_response.name` field

**Migration Required:**

```php
// Before (v0.8)
new ToolResultMessage($result, $toolCallId, $metadata);

// After (v1.0)
new ToolResultMessage($result, $toolCallId, $toolName, $metadata);

// Or use the Message facade (recommended)
Message::toolResult($result, $toolCallId, $toolName, $metadata);
```

#### 3. ToolCall Now Extends DataModel

**What Changed:**

-   `ToolCall` class now extends `DataModel` for proper serialization
-   Uses nested `ToolCallFunction` DataModel for function details
-   Implements `toArray()` and `fromArray()` methods

**Migration Required:**

No changes needed if using `ToolCall` through the interface. Direct property access may need updates:

```php
// Before (v0.8) - direct properties
$name = $toolCall->name;
$args = $toolCall->arguments;

// After (v1.0) - use interface methods (unchanged)
$name = $toolCall->getToolName();
$args = $toolCall->getArguments();
```

#### 4. Base Message Class Refactored

**What Changed:**

-   All messages now have unique `$id` property (auto-generated UUID)
-   New `$extras` array for driver-specific/unknown fields
-   `$content` removed from base class - each child defines its own typed content
-   Content is always a DataModel (`TextContent`, `MessageContent`, `ToolResultContent`)

**Migration Required:**

```php
// Accessing message ID (new feature)
$id = $message->getId(); // e.g., 'msg_abc123...'

// Accessing extras (new feature)
$message->setExtra('custom_field', 'value');
$value = $message->getExtra('custom_field');
```

#### 5. Driver Methods Deprecated

**What Changed:**

The following methods are deprecated in all drivers:

-   `toolCallsToMessage()` → Use `MessageFormatter::formatToolCallMessage()`
-   `toolResultToMessage()` → Use `MessageFormatter::formatToolResultMessage()`

**No immediate action required** - methods still work but will be removed in future.

### How to Upgrade

1. **Replace Message::create() calls**:

    ```php
    // Use typed factory methods instead
    Message::user($content)
    Message::assistant($content)
    Message::system($content)
    Message::developer($content)
    Message::toolCall($toolCalls)
    Message::toolResult($content, $toolCallId, $toolName)
    ```

2. **Update ToolResultMessage instantiation**:

    ```php
    new ToolResultMessage($result, $toolCallId, $toolName);
    ```

3. **Update custom drivers** (if you have custom LLM drivers):
    - Implement `MessageFormatter` for your driver
    - Use formatter methods instead of deprecated driver methods
    - See `OpenAiMessageFormatter` as reference

### Added

-   `DriverConfig` DTO for type-safe driver configuration
-   `DriverConfig::fromArray()`, `::wrap()`, `::merge()`, `::withExtra()` methods
-   `DriverConfig::set()`, `::get()`, `::has()`, `::getExtra()`, `::getExtras()` methods
-   `LlmDriver::getDriverConfig()` for typed access to driver configuration
-   `MessageFormatter` interface for driver message transformation
-   `OpenAiMessageFormatter`, `ClaudeMessageFormatter`, `GeminiMessageFormatter` implementations
-   `ToolCall` now extends `DataModel` with proper serialization
-   `ToolCallFunction` DataModel for nested function details
-   `ToolCallArray` DataModelArray for collections of tool calls
-   `ToolResultContent::$tool_name` property for Gemini compatibility
-   `Message::$id` - unique identifier for each message (auto-generated)
-   `Message::$extras` - storage for driver-specific/unknown fields
-   `Message::getExtras()`, `setExtras()`, `getExtra()`, `setExtra()`, `hasExtra()`, `removeExtra()`
-   `DataModelArray::findItem()`, `getItem()`, `setItem()`, `hasItem()`, `removeItem()` methods
-   **Laravel AI SDK integration** — full optional driver layer that delegates to the Laravel AI SDK (`laravel/ai`). Requires PHP 8.4+ and Laravel 12+. Enables LarAgent agents to run through the SDK's Prism runtime, accessing any provider configured in `config/ai.php` (OpenAI, Anthropic, Gemini, Groq, Ollama, Azure, xAI, DeepSeek, Mistral, Cohere, OpenRouter) without additional driver code.
-   `LaravelAiDriver` (`Drivers\LaravelAi\LaravelAiDriver`) — `HookableDriver` implementation that orchestrates the SDK integration. Supports both synchronous (`sendMessage`) and streaming (`sendMessageStreamed`) execution. Handles system instruction extraction, prompt delegation, tool bridging, intermediate message capture, and usage tracking. The SDK owns the tool execution loop; the driver injects LarAgent hook callbacks so `beforeToolExecution`/`afterToolExecution` events still fire normally.
-   `HookableDriver` interface (`Core\Contracts\HookableDriver`) — new driver contract for drivers where the LLM provider (or an intermediary like the SDK) handles the tool call loop internally. Defines `setHookCallbacks(?Closure $before, ?Closure $after): self` so the `LarAgent` engine can inject hook closures without needing to manage the loop itself.
-   `ConfigBridge` (`Drivers\LaravelAi\ConfigBridge`) — maps LarAgent provider labels (e.g., `'openai'`, `'claude'`, `'gemini'`) to SDK provider names via a `toLabEnum()` method covering 11 providers. Also provides `isSdkAvailable()` to check for the SDK package at runtime before instantiating the driver.
-   `MessageConverter` (`Drivers\LaravelAi\MessageConverter`) — bidirectional message conversion between LarAgent `Message` objects and SDK `\Laravel\Ai\Messages\Message` objects. Handles: system/developer instruction extraction (`extractInstructions`), LarAgent-to-SDK conversion (`toLaravelAiMessages`) that filters out tool call/result messages (owned by SDK), SDK-to-LarAgent response conversion (`fromSdkResponse`) with usage mapping, intermediate tool message extraction (`extractIntermediateMessages`) that reconstructs `ToolCallMessage`/`ToolResultMessage` pairs from SDK response steps for accurate chat history, and last-user-message extraction for the SDK's prompt parameter.
-   `SdkToolBridge` (`Drivers\LaravelAi\SdkToolBridge`) — adapter that wraps LarAgent `Tool` objects as SDK `\Laravel\Ai\Contracts\Tool` implementations. Bridges `name()`, `description()`, `schema()` (converting LarAgent property definitions to SDK `JsonSchema` format), and `handle()` (firing `beforeToolExecution`/`afterToolExecution` hooks around `$tool->execute()`). A hook returning `false` short-circuits execution with a cancellation message. `fromLarAgentTools()` factory creates bridges for an entire tool array in one call.
-   SDK capability tools (`Tools\LaravelAi\`) — four ready-made tools that expose SDK-specific capabilities to any LarAgent agent:
    -   `EmbeddingTool` — generates vector embeddings via `Laravel\Ai\embed()`. Configurable provider and model via `usingProvider()`/`usingModel()` fluent API. Returns JSON float array.
    -   `ImageGenerationTool` — generates images via `Laravel\Ai\image()`. Accepts prompt and optional size parameter. Configurable provider/model. Returns image URL.
    -   `SimilaritySearchTool` — performs vector similarity search via the SDK's `SimilaritySearch` tool. Configurable Eloquent model class, embedding column, minimum similarity threshold, result limit, and optional query callback via constructor or `usingModel()` factory.
    -   `WebSearchTool` — performs web searches via the SDK's `WebSearch` provider tool. Configurable max results, allowed/blocked domains via `max()`/`allow()`/`block()` fluent API.
-   `ImplementsSdkAgent` trait (`Concerns\ImplementsSdkAgent`) — makes any LarAgent agent class compatible with the SDK's `\Laravel\Ai\Contracts\Agent` interface. Bridges `instructions()`, `tools()` (via `SdkToolBridge`), and `messages()` (via `MessageConverter`). Enables reverse usage: LarAgent agents can be passed to SDK code that expects an SDK Agent.
-   `laravel-ai` provider entry in default config (`config/laragent.php`) — preconfigured provider block using `LaravelAiDriver` with `sdk_provider` setting for selecting the SDK provider. Includes comments noting PHP 8.4+/Laravel 12+ requirement.
-   `laravel/ai` added to `suggest` in `composer.json` (with requirement note) and to `require-dev` as `^0.2.1` for running the integration test suite
-   53 new tests across 4 test files (`tests/Unit/Drivers/LaravelAi/`) covering `ConfigBridge`, `MessageConverter`, `SdkToolBridge`, and `LaravelAiDriver` — all using mocks/fakes without requiring the actual SDK package installed
-   **Full SDK ↔ LarAgent Context & Storage integration** — the SDK driver now shares LarAgent's storage, truncation, usage tracking, session identity, and event system instead of maintaining separate layers. This makes `LaravelAiDriver` a first-class citizen with identical Context/Storage guarantees as native drivers.
-   `SessionAwareDriver` interface (`Core\Contracts\SessionAwareDriver`) — new driver contract for drivers that need session identity context. Defines `setSessionIdentity(SessionIdentity $identity): static` and `getSessionIdentity(): ?SessionIdentity`. `LaravelAiDriver` implements this; `Agent::prepareAgent()` calls it automatically when the driver supports it.
-   `SessionIdentityBridge` (`Drivers\LaravelAi\SessionIdentityBridge`) — bidirectional mapping between LarAgent's `SessionIdentity` and SDK conversation concepts (`userId`, `conversationId`). Methods: `toSdkUserId()`, `toSdkConversationId()`, `fromSdkConversation()`, `toConversable()`, `roundTrip()`.
-   `LarAgentConversationStore` (`Drivers\LaravelAi\LarAgentConversationStore`) — implements the SDK's `ConversationStore` interface backed by LarAgent's `ChatHistoryStorage`. Makes LarAgent's storage the single source of truth for conversation persistence when using the SDK driver. Supports `latestConversationId()`, `storeConversation()`, `storeUserMessage()`, `storeAssistantMessage()`, `getLatestConversationMessages()`, and `getChatHistory()`.
-   `SdkEventBridge` (`Drivers\LaravelAi\SdkEventBridge`) — bridges SDK events (`PromptingAgent`, `AgentPrompted`) to LarAgent events (`BeforeSend`, `AfterResponse`) so observability is unified regardless of which driver is used. Includes a guard mechanism to prevent double-dispatch for tool events already handled by `SdkToolBridge`.
-   Per-step usage extraction in `MessageConverter` — `extractIntermediateMessages()` now reads `$step->usage` from each SDK Step and attaches it to `ToolResultMessage` via extras. New `aggregateStepUsage()` method sums all step usages + final response usage so `getLastKnownTotalTokens()` finds accurate cumulative data and truncation works correctly with SDK responses.
-   `conversation_store` and `bridge_events` config keys under the `laravel-ai` provider in `config/laragent.php`
-   41 new tests across 4 new test files covering `SessionIdentityBridge`, `LarAgentConversationStore`, `SdkEventBridge`, and SDK truncation integration

### Changed

-   `LaravelAiDriver` now implements `SessionAwareDriver` — receives session identity from `Agent::prepareAgent()` and passes it to `SessionIdentityBridge` and `LarAgentConversationStore`
-   `Agent::prepareAgent()` now passes session identity to `SessionAwareDriver` implementations and `AgentDTO` to `LaravelAiDriver` for event bridging
-   `MessageConverter::fromSdkResponse()` now uses aggregated usage (sum of all step usages + final response usage) instead of only the final response usage
-   `MessageConverter::extractIntermediateMessages()` now attaches per-step usage as metadata on `ToolResultMessage` instances
-   `LlmDriver` constructor now accepts `DriverConfig|array` (backward compatible)
-   `LlmDriver::sendMessage()` and `sendMessageStreamed()` accept `DriverConfig|array` for override settings
-   `LarAgent` class now stores `DriverConfig` internally instead of individual properties
-   `Message` base class is now abstract with no `$content` property
-   Each message type defines its own typed content (`TextContent`, `MessageContent`, etc.)
-   `Message` facade is now a pure factory (static methods only)
-   `ToolCall` extends `DataModel` instead of being a plain class
-   All drivers use `MessageFormatter` for message/response transformation
-   `ToolResultMessage` constructor requires `toolName` parameter

### Deprecated

-   `LlmDriver::toolCallsToMessage()` - Use `MessageFormatter` instead
-   `LlmDriver::toolResultToMessage()` - Use `MessageFormatter` instead

### Removed

-   `Message::create()` - Use typed factory methods (`Message::user()`, etc.)
-   `Message::fromArray()` - Use specific message class `fromArray()` or `MessageArray`
-   `Message::fromJSON()` - Use specific message class methods

---

## [v0.8] - Previous Release

### ⚠️ Breaking Changes (v0.7 → v0.8)

#### 1. Tool Execution Events Now Include ToolCall Object

**What Changed:**

-   `BeforeToolExecution` and `AfterToolExecution` events now include the `ToolCall` object
-   Hook callbacks for `beforeToolExecution()` and `afterToolExecution()` receive additional parameters

**Migration Required:**

**Event Listeners:**

```php
// Before (v0.7)
Event::listen(BeforeToolExecution::class, function ($event) {
    // $event->tool available
    // $event->toolCall NOT available
});

// After (v0.8)
Event::listen(BeforeToolExecution::class, function ($event) {
    // $event->tool available
    // $event->toolCall NOW available - contains ID, name, arguments
    Log::info('Tool call', [
        'id' => $event->toolCall->getId(),
        'tool' => $event->toolCall->getToolName(),
        'args' => json_decode($event->toolCall->getArguments(), true),
    ]);
});
```

**Hook Callbacks:**

```php
// Before (v0.7)
$agent->beforeToolExecution(function($agent, $tool) {
    // 2 parameters
});

$agent->afterToolExecution(function($agent, $tool, &$result) {
    // 3 parameters
});

// After (v0.8)
$agent->beforeToolExecution(function($agent, $tool, $toolCall) {
    // 3 parameters - $toolCall added
    logger()->info("Executing: {$toolCall->getToolName()}", [
        'call_id' => $toolCall->getId(),
    ]);
});

$agent->afterToolExecution(function($agent, $tool, $toolCall, &$result) {
    // 4 parameters - $toolCall added
    logger()->info("Completed: {$toolCall->getToolName()}", [
        'call_id' => $toolCall->getId(),
        'result' => $result,
    ]);
});
```
