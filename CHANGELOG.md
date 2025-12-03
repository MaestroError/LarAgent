# Changelog

All notable changes to `LarAgent` will be documented in this file.

## [v1.0] - Unreleased

### ⚠️ Breaking Changes (v0.8 → v1.0)

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

### Changed

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
