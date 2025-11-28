# Message Standardization Plan

## Overview

This document outlines a plan to standardize all LarAgent message types with a clear separation between:

1. **Internal Format** - Structured DataModel objects used within LarAgent
2. **Wire Format** - Driver-specific arrays sent to/from LLM APIs

## Key Design Decisions

### Decision 1: Content as DataModel/DataModelArray

All message content will be structured as `DataModelContract` or `DataModelArrayContract`:

-   **No primitive `string` content** - Even simple text uses `TextContent` DataModel
-   **Parent Message class has NO `$content` property** - Children define their own typed content
-   **`getContent()` and `setContent()` are abstract** - Each child implements with proper types

### Decision 2: Message ID for Chat History Operations

Each message will have a unique `id` field:

-   **Included in `toArray()` output** - For storage and retrieval
-   **Excluded from `toSchema()`** - Not sent to LLM APIs
-   **Auto-generated UUID** if not provided
-   **Enables individual message operations** in chat history (get, set, remove by ID)

### Decision 3: DataModelArray Enhancements

Add item management methods to `DataModelArray`:

-   **`findItem(key, value)`** - Internal helper reused by other methods
-   **`getItem(key, value)`** - Get item by key/value match
-   **`setItem(key, value, newItem)`** - Replace or add item
-   **`hasItem(key, value)`** - Check if item exists
-   **`removeItem(key, value)`** - Remove item (renamed from `remove()`)
-   **Key/value only** - No index or object instance access for these methods

### Decision 4: Replace Dynamic Properties with `$extras`

Replace `__get()/__set()` dynamic properties with explicit `$extras` array:

-   **Typed, discoverable property** - `protected array $extras = []`
-   **Stored in `toArray()` output** - Appears as `'extras'` field for storage
-   **Auto-populated from unknown keys** - `fromArray()` puts unrecognized fields into `$extras`
-   **Excluded from schema** - Not sent to LLM APIs via `#[ExcludeFromSchema]`
-   **Getter/setter methods** - `getExtras()`, `setExtras()`, `getExtra($key)`, `setExtra($key, $value)`
-   **Separate from `$metadata`** - `$metadata` is explicit developer-provided context, `$extras` is auto-captured or included from Driver
-   **Driver usage pattern** - Drivers can use `$extras` for driver-specific fields

### Decision 5: Content Property Always DataModel

The `$content` property must always be a DataModel object, but constructors accept strings for convenience:

-   **Property is always `TextContent`/`MessageContent`** - Even simple text stored as DataModel
-   **Constructors accept `string`** - For developer convenience, converted internally
-   **Internal conversion** - `string` → `TextContent` happens in constructor
-   **Consistent serialization** - All content goes through `toArray()` / `fromArray()`
-   **Type safety** - `$content` property has clear DataModel type hints

---

## Current State Analysis

### Problems with Current Architecture

1. **Mixed Responsibilities**

    - Messages store driver-specific data via dynamic properties (`$this->__set()`)
    - `toArray()` outputs driver-specific format, not a canonical format
    - No clear separation between storage format and API format

2. **Driver-Specific Storage**

    - `toolCallsToMessage()` returns different structures per driver:
        - OpenAI: `['role' => 'assistant', 'tool_calls' => [...]]`
        - Gemini: `['role' => 'assistant', 'parts' => [['functionCall' => ...]]]`
        - Claude: `['role' => 'assistant', 'content' => [['type' => 'tool_use', ...]]]`
    - Chat history is driver-locked

3. **Inconsistent Message Construction**

    - `ToolCallMessage` takes `(array $toolCalls, array $message, array $metadata)`
    - `ToolResultMessage` takes `(array $message, array $metadata)`
    - `UserMessage` takes `(string $content, array $metadata)`
    - No unified approach

4. **Dynamic Properties for Driver Extras**
    - `parts`, `tool_calls`, `tool_call_id` stored as dynamic properties
    - Not type-safe, not discoverable, not schema-able

### Current Message Flow

```
API Response → Driver extracts data → Creates Message object → Stores in ChatHistory
                                              ↓
ChatHistory → toArray() → Driver receives array → preparePayload() → API Request
```

**Problem**: Messages store driver-specific format, making them non-portable.

---

## Proposed Architecture

### Core Principle

> **Messages store canonical format. Drivers transform to/from wire format.**

### Canonical Format Definition

Use an OpenAI-compatible structure as the canonical format since it's the most widely adopted:

```php
// UserMessage canonical (with id excluded from wire format)
['role' => 'user', 'content' => 'Hello']
// Internal: $id = 'msg_abc123', $content = TextContent

// AssistantMessage canonical
['role' => 'assistant', 'content' => 'Hi there!']
// Internal: $id = 'msg_def456', $content = TextContent

// ToolCallMessage canonical
[
    'role' => 'assistant',
    'tool_calls' => [
        ['id' => '...', 'type' => 'function', 'function' => ['name' => '...', 'arguments' => '{...}']]
    ]
]
// Internal: $id = 'msg_ghi789', $toolCalls = ToolCallArray

// ToolResultMessage canonical
['role' => 'tool', 'content' => '...', 'tool_call_id' => '...']
// Internal: $id = 'msg_jkl012', $content = ToolResultContent
```

### Content Type Mapping

| Message Type        | Content Property Type | Constructor Accepts        | Wire Format                          |
| ------------------- | --------------------- | -------------------------- | ------------------------------------ |
| `UserMessage`       | `?MessageContent`     | `string \| MessageContent` | `string` or `array` of content parts |
| `AssistantMessage`  | `?TextContent`        | `string \| TextContent`    | `string`                             |
| `SystemMessage`     | `?TextContent`        | `string \| TextContent`    | `string`                             |
| `DeveloperMessage`  | `?TextContent`        | `string \| TextContent`    | `string`                             |
| `ToolCallMessage`   | `null`                | N/A (uses `$toolCalls`)    | N/A (no content)                     |
| `ToolResultMessage` | `?ToolResultContent`  | `ToolResultContent`        | `string` (content field)             |

### Existing Content DataModels (already exist)

```
MessageContent (DataModelArray)
├── TextContent      - { type: 'text', text: '...' }
├── ImageContent     - { type: 'image_url', image_url: { url: '...' } }
└── AudioContent     - { type: 'input_audio', input_audio: { format: '...', data: '...' } }
```

### New Content DataModels (to create)

```
ToolResultContent (DataModel)
├── content: string
└── tool_call_id: string

ToolCallData (DataModel) - implements ToolCallInterface
├── id: string
├── type: string = 'function'
└── function: ToolCallFunction
    ├── name: string
    └── arguments: string (JSON)

ToolCallArray (DataModelArray)
└── items: ToolCallData[]
```

### New Message Flow

```
API Response → Driver.parseResponse() → Creates canonical Message → Stores in ChatHistory
                                                    ↓
ChatHistory → messages array → Driver.formatMessages() → API Request
```

---

## Implementation Plan

### Phase 1: Update `ToolCall` to Extend DataModel

Update existing ToolCall class to extend DataModel for proper serialization:

```php
// src/ToolCall.php
namespace LarAgent;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\DataModels\ToolCallFunction;
use LarAgent\Attributes\Desc;

class ToolCall extends DataModel implements ToolCallInterface
{
    #[Desc('Unique identifier for the tool call')]
    public string $id;

    #[Desc('Type of tool call, always "function"')]
    public string $type = 'function';

    #[Desc('Function details')]
    public ToolCallFunction $function;

    public function __construct(string $id, string $name, string $arguments)
    {
        $this->id = $id;
        $this->type = 'function';
        $this->function = new ToolCallFunction($name, $arguments);
    }

    // ToolCallInterface methods
    public function getId(): string { return $this->id; }
    public function getToolName(): string { return $this->function->name; }
    public function getArguments(): string { return $this->function->arguments; }
}
```

```php
// src/Messages/DataModels/ToolCallFunction.php
namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModel;

class ToolCallFunction extends DataModel
{
    public string $name;
    public string $arguments;

    public function __construct(string $name, string $arguments)
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
```

**Decision Point**: Keep existing `ToolCall` interface or replace with `ToolCallData`?

**Decision**:

-   Keep `ToolCallInterface` contract (at `src/Core/Contracts/ToolCall.php`)
-   Update existing `ToolCall` class to extend `DataModel`
-   No new `ToolCallData` class needed - enhance existing `ToolCall`

### Phase 1.1: Enhance DataModelArray with Item Methods

Add methods to `DataModelArray` for key-based item access:

```php
// src/Core/Abstractions/DataModelArray.php - additions

/**
 * Find an item's index by a key/value match.
 * Internal helper reused by other methods.
 *
 * @param string $key The property key to match
 * @param mixed $value The value to match
 * @return int|null The index of the found item, or null
 */
protected function findItem(string $key, mixed $value): ?int
{
    foreach ($this->items as $index => $item) {
        if ($item instanceof DataModelContract && isset($item[$key]) && $item[$key] === $value) {
            return $index;
        }
    }
    return null;
}

/**
 * Get an item by a key/value match.
 *
 * @param string $key The property key to match
 * @param mixed $value The value to match
 * @return DataModelContract|null
 */
public function getItem(string $key, mixed $value): ?DataModelContract
{
    $index = $this->findItem($key, $value);
    return $index !== null ? $this->items[$index] : null;
}

/**
 * Set (replace) an item by a key/value match.
 * If no matching item found, adds the new item.
 *
 * @param string $key The property key to match
 * @param mixed $value The value to match
 * @param DataModelContract $newItem The new item to set
 * @return static
 */
public function setItem(string $key, mixed $value, DataModelContract $newItem): static
{
    $this->validateAllowedModel($newItem);

    $index = $this->findItem($key, $value);
    if ($index !== null) {
        $this->items[$index] = $newItem;
    } else {
        $this->items[] = $newItem;
    }

    return $this;
}

/**
 * Check if an item with the given key/value exists.
 *
 * @param string $key The property key to match
 * @param mixed $value The value to match
 * @return bool
 */
public function hasItem(string $key, mixed $value): bool
{
    return $this->findItem($key, $value) !== null;
}

/**
 * Remove an item by a key/value match.
 *
 * @param string $key The property key to match
 * @param mixed $value The value to match
 * @return static
 */
public function removeItem(string $key, mixed $value): static
{
    $index = $this->findItem($key, $value);
    if ($index !== null) {
        array_splice($this->items, $index, 1);
    }
    return $this;
}
```

### Phase 1.2: Update MessageInterface

```php
// src/Core/Contracts/Message.php
namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\DataModel as DataModelContract;

interface Message
{
    /**
     * Get unique message identifier
     */
    public function getId(): string;

    /**
     * Get message role
     */
    public function getRole(): string;

    /**
     * Get message content as DataModel
     */
    public function getContent(): ?DataModelContract;

    /**
     * Set message content
     */
    public function setContent(?DataModelContract $content): void;

    /**
     * Get content as plain string (convenience method)
     */
    public function getContentAsString(): string;

    /**
     * Get arbitrary property value
     */
    public function get(string $key): mixed;

    /**
     * Get message metadata
     */
    public function getMetadata(): array;

    /**
     * Set message metadata
     */
    public function setMetadata(array $data): void;

    /**
     * Convert to canonical array format
     */
    public function toArray(): array;

    /**
     * Convert to array including metadata
     */
    public function toArrayWithMeta(): array;

    /**
     * JSON serialization
     */
    public function jsonSerialize(): array;
}
```

### Phase 2: Refactor Message Base Class

#### Remove `$content` from Parent - Children Define Content as DataModel

```php
// src/Core/Abstractions/Message.php
abstract class Message extends DataModel implements MessageInterface
{
    #[ExcludeFromSchema]
    #[Desc('Unique identifier for the message')]
    public string $id;

    #[Desc('The role of the message sender')]
    public string|Role $role;

    protected array $metadata = [];

    /**
     * Extra fields not defined in class properties.
     * Stores driver-specific or unknown fields from deserialization.
     * Excluded from schema (not sent to LLM API).
     */
    #[ExcludeFromSchema]
    protected array $extras = [];

    // NO $content property - each child defines its own as DataModelContract

    public function __construct()
    {
        // Auto-generate ID if not set
        $this->id = $this->id ?? $this->generateId();
    }

    protected function generateId(): string
    {
        return 'msg_' . bin2hex(random_bytes(12));
    }

    /**
     * Get message content - children implement with proper DataModel types
     */
    abstract public function getContent(): ?DataModelContract;

    /**
     * Set message content - children implement with proper DataModel types
     */
    abstract public function setContent(?DataModelContract $content): void;

    /**
     * Get content as string (for simple text extraction)
     */
    public function getContentAsString(): string
    {
        $content = $this->getContent();
        if ($content === null) {
            return '';
        }
        // Delegate to content's string representation
        return (string) $content;
    }

    // ========== Extras Management ==========

    /**
     * Get all extra fields
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    /**
     * Set all extra fields
     */
    public function setExtras(array $extras): void
    {
        $this->extras = $extras;
    }

    /**
     * Get a single extra field
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }

    /**
     * Set a single extra field
     */
    public function setExtra(string $key, mixed $value): void
    {
        $this->extras[$key] = $value;
    }

    /**
     * Check if an extra field exists
     */
    public function hasExtra(string $key): bool
    {
        return array_key_exists($key, $this->extras);
    }

    /**
     * Remove an extra field
     */
    public function removeExtra(string $key): void
    {
        unset($this->extras[$key]);
    }

    // ========== Serialization ==========

    /**
     * Override toArray to include extras for storage
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        // Include extras if not empty
        if (!empty($this->extras)) {
            $data['extras'] = $this->extras;
        }

        return $data;
    }

    /**
     * Override fromArray to capture unknown fields in extras
     */
    public static function fromArray(array $data): static
    {
        $instance = parent::fromArray($data);

        // Collect known property names
        $reflection = new \ReflectionClass(static::class);
        $knownProperties = [];
        foreach ($reflection->getProperties() as $property) {
            $knownProperties[] = $property->getName();
        }

        // Any array key not matching a known property goes to extras
        foreach ($data as $key => $value) {
            if ($key === 'extras') {
                // Merge stored extras
                $instance->extras = array_merge($instance->extras, $value);
            } elseif (!in_array($key, $knownProperties)) {
                // Unknown field -> extras
                $instance->extras[$key] = $value;
            }
        }

        return $instance;
    }
}
```

**Child classes define their content as DataModels:**

```php
// UserMessage - MessageContent (can contain TextContent, ImageContent, AudioContent)
class UserMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'user';

    #[Desc('The content of the user message')]
    public ?MessageContent $content = null;

    public function __construct(string|MessageContent $content, array $metadata = [])
    {
        parent::__construct();
        $this->content = is_string($content)
            ? new MessageContent([new TextContent($content)])
            : $content;
        $this->metadata = $metadata;
    }

    public function getContent(): ?MessageContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof MessageContent)) {
            throw new \InvalidArgumentException('UserMessage content must be MessageContent or null');
        }
        $this->content = $content;
    }
}

// AssistantMessage - TextContent only
class AssistantMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'assistant';

    #[Desc('The content of the assistant response')]
    public ?TextContent $content = null;

    public function __construct(string|TextContent $content, array $metadata = [])
    {
        parent::__construct();
        $this->content = is_string($content)
            ? new TextContent($content)
            : $content;
        $this->metadata = $metadata;
    }

    public function getContent(): ?TextContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof TextContent)) {
            throw new \InvalidArgumentException('AssistantMessage content must be TextContent');
        }
        $this->content = $content;
    }
}

// ToolCallMessage - no content, has ToolCallArray
class ToolCallMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'assistant';

    #[Desc('Array of tool calls requested by the assistant')]
    public ToolCallArray $toolCalls;

    public function __construct(ToolCallArray|array $toolCalls, array $metadata = [])
    {
        parent::__construct();
        $this->toolCalls = $toolCalls instanceof ToolCallArray
            ? $toolCalls
            : new ToolCallArray($toolCalls);
        $this->metadata = $metadata;
    }

    public function getContent(): ?DataModelContract
    {
        return null; // ToolCallMessage has no content
    }

    public function setContent(?DataModelContract $content): void
    {
        // No-op for ToolCallMessage
    }

    public function getToolCalls(): ToolCallArray
    {
        return $this->toolCalls;
    }
}

// ToolResultMessage - ToolResultContent with result and tool_call_id
class ToolResultMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'tool';

    #[Desc('The result content from tool execution')]
    public ?ToolResultContent $content = null;

    public function __construct(ToolResultContent $content, array $metadata = [])
    {
        parent::__construct();
        $this->content = $content;
        $this->metadata = $metadata;
    }

    public function getContent(): ?ToolResultContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof ToolResultContent)) {
            throw new \InvalidArgumentException('ToolResultMessage content must be ToolResultContent');
        }
        $this->content = $content;
    }
}

// SystemMessage - TextContent
class SystemMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'system';

    public ?TextContent $content = null;

    public function __construct(string|TextContent $content, array $metadata = [])
    {
        parent::__construct();
        $this->content = is_string($content)
            ? new TextContent($content)
            : $content;
        $this->metadata = $metadata;
    }

    public function getContent(): ?TextContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof TextContent)) {
            throw new \InvalidArgumentException('SystemMessage content must be TextContent');
        }
        $this->content = $content;
    }
}

// DeveloperMessage - TextContent
class DeveloperMessage extends Message
{
    #[ExcludeFromSchema]
    public string $role = 'developer';

    public ?TextContent $content = null;

    public function __construct(string|TextContent $content, array $metadata = [])
    {
        parent::__construct();
        $this->content = is_string($content)
            ? new TextContent($content)
            : $content;
        $this->metadata = $metadata;
    }

    public function getContent(): ?TextContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof TextContent)) {
            throw new \InvalidArgumentException('DeveloperMessage content must be TextContent');
        }
        $this->content = $content;
    }
}
```

### Phase 2.1: Create New Content DataModels

```php
// src/Messages/DataModels/ToolResultContent.php
namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ToolResultContent extends DataModel
{
    #[Desc('The result content from the tool')]
    public string $content;

    #[Desc('The ID of the tool call this result responds to')]
    public string $tool_call_id;

    public function __construct(string $content = '', string $toolCallId = '')
    {
        $this->content = $content;
        $this->tool_call_id = $toolCallId;
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
```

```php
// src/Messages/DataModels/ToolCallArray.php
namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\ToolCall;

class ToolCallArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [ToolCall::class];
    }

    public function discriminator(): string
    {
        return 'type'; // All items are 'function' type
    }
}
```

### Phase 2.2: Add `__toString()` to TextContent

```php
// Update src/Messages/DataModels/Content/TextContent.php
class TextContent extends DataModel
{
    public string $type = MessageContentType::TEXT->value;
    public string $text;

    public function __toString(): string
    {
        return $this->text;
    }
}
```

### Phase 3: Update `src/Message.php` Facade

The `Message` facade class serves as a factory for creating messages. It should remain but be updated:

```php
// src/Message.php
class Message
{
    // Factory methods - keep these
    public static function user(string|MessageContent $content, array $metadata = []): UserMessage
    {
        return new UserMessage($content, $metadata);
    }

    public static function assistant(string|TextContent $content, array $metadata = []): AssistantMessage
    {
        return new AssistantMessage($content, $metadata);
    }

    public static function system(string|TextContent $content, array $metadata = []): SystemMessage
    {
        return new SystemMessage($content, $metadata);
    }

    public static function developer(string|TextContent $content, array $metadata = []): DeveloperMessage
    {
        return new DeveloperMessage($content, $metadata);
    }

    public static function toolCall(ToolCallArray|array $toolCalls, array $metadata = []): ToolCallMessage
    {
        return new ToolCallMessage($toolCalls, $metadata);
    }

    public static function toolResult(ToolResultContent $content, array $metadata = []): ToolResultMessage
    {
        return new ToolResultMessage($content, $metadata);
    }

    // REMOVE: create() for arbitrary messages - too dynamic
    // REMOVE: fromArray() - use MessageArray for polymorphic deserialization
    // REMOVE: fromJSON() - use specific message types
}
```

**Question**: Should `Message` extend `AbstractMessage`?

**Analysis**: Currently it does, allowing `Message::create()` for arbitrary messages. If we remove `$content` from parent, `Message` itself becomes problematic.

**Recommendation**:

-   `Message` should NOT extend `AbstractMessage`
-   `Message` becomes pure factory (static methods only)
-   Deprecate `Message::create()`, `Message::fromArray()`, `Message::fromJSON()`

DONE

### Phase 4: Add Driver Message Formatting Interface

#### Clear Responsibility Separation

**Driver Responsibility:**

-   Works with arrays only (sends arrays to API, receives arrays from API)
-   Builds payload arrays and passes to formatter for object conversion
-   Receives Message objects and asks formatter to create driver-specific arrays
-   Handles HTTP communication, authentication, streaming
-   No knowledge of LarAgent Message internals

**MessageFormatter Responsibility:**

-   Converts LarAgent Message objects → driver-specific arrays
-   Extracts data from driver response arrays → LarAgent objects
-   Pure transformation, no side effects, no HTTP

#### Interface Design

```php
// src/Core/Contracts/MessageFormatter.php
namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;

interface MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to driver-specific array format.
     * Handles all message types: User, Assistant, System, Developer, ToolCall, ToolResult.
     *
     * @param MessageInterface $message Any LarAgent message object
     * @return array Driver-specific message array
     */
    public function formatMessage(MessageInterface $message): array;

    /**
     * Convert an array of LarAgent messages to driver-specific format.
     * Simply iterates and calls formatMessage() for each.
     * May skip certain message types (e.g., system messages handled separately).
     *
     * @param MessageInterface[] $messages Array of LarAgent message objects
     * @return array Array of driver-specific message arrays
     */
    public function formatMessages(array $messages): array;

    /**
     * Convert LarAgent tools to driver-specific format.
     *
     * @param ToolInterface[] $tools Array of LarAgent tool objects
     * @return array Driver-specific tools array (for payload)
     */
    public function formatTools(array $tools): array;

    // ========== Driver → LarAgent (extracting from API response) ==========

    /**
     * Extract usage/token information from driver response.
     *
     * @param array $response Raw API response array
     * @return array Normalized usage array ['prompt_tokens' => int, 'completion_tokens' => int, 'total_tokens' => int]
     */
    public function extractUsage(array $response): array;

    /**
     * Extract tool calls from driver response.
     * Returns array of ToolCall objects (LarAgent format).
     *
     * @param array $response Raw API response array
     * @return ToolCall[] Array of LarAgent ToolCall objects
     */
    public function extractToolCalls(array $response): array;

    /**
     * Extract text content from driver response.
     *
     * @param array $response Raw API response array
     * @return string The text content from the response
     */
    public function extractContent(array $response): string;

    /**
     * Extract finish reason from driver response.
     * Returns normalized values: 'stop', 'tool_calls', 'length', 'content_filter'
     *
     * @param array $response Raw API response array
     * @return string Normalized finish reason
     */
    public function extractFinishReason(array $response): string;
}
```

**Note**: Some formatters may need additional methods:

```php
// Gemini/Claude-specific: Extract system instruction separately
public function extractSystemInstruction(array $messages): ?array;

// Claude-specific: Format content as array of parts (always required by Claude)
protected function formatContentParts(MessageInterface $message): array;
```

#### Why This Design Works

1. **Driver stays simple**: Just deals with arrays, HTTP, and delegation
2. **Formatter is pure transformation**: No side effects, easy to test
3. **No `toolResultToMessage()` needed**: Driver creates `ToolResultMessage` object, formatter handles conversion
4. **No `toolCallsToMessage()` needed**: Driver creates `ToolCallMessage` object, formatter handles conversion
5. **Consistent pattern**: All message types go through same `formatMessage()` method

### Phase 5: Implement MessageFormatter in Drivers

Each driver implements the formatting. The formatter is a pure transformation layer.

```php
// src/Drivers/OpenAi/OpenAiMessageFormatter.php
class OpenAiMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver ==========

    public function formatMessage(MessageInterface $message): array
    {
        return match (true) {
            $message instanceof ToolCallMessage => $this->formatToolCallMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof SystemMessage => $this->formatSystemMessage($message),
            $message instanceof DeveloperMessage => $this->formatDeveloperMessage($message),
            default => throw new \InvalidArgumentException('Unknown message type: ' . get_class($message)),
        };
    }

    public function formatMessages(array $messages): array
    {
        return array_map(fn($msg) => $this->formatMessage($msg), $messages);
    }

    public function formatTools(array $tools): array
    {
        return array_map(fn(ToolInterface $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->getProperties(),
                    'required' => $tool->getRequired(),
                ],
            ],
        ], $tools);
    }

    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        return [
            'role' => 'assistant',
            'tool_calls' => array_map(fn(ToolCall $tc) => [
                'id' => $tc->getId(),
                'type' => 'function',
                'function' => [
                    'name' => $tc->getToolName(),
                    'arguments' => $tc->getArguments(),
                ],
            ], $message->getToolCalls()->all()),
        ];
    }

    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'tool',
            'content' => (string) $message->getContent(),
            'tool_call_id' => $message->getContent()->tool_call_id,
        ];
    }

    protected function formatUserMessage(UserMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => $message->getContentAsString(),
        ];
    }

    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        return [
            'role' => 'assistant',
            'content' => $message->getContentAsString(),
        ];
    }

    protected function formatSystemMessage(SystemMessage $message): array
    {
        return [
            'role' => 'system',
            'content' => $message->getContentAsString(),
        ];
    }

    protected function formatDeveloperMessage(DeveloperMessage $message): array
    {
        return [
            'role' => 'developer',
            'content' => $message->getContentAsString(),
        ];
    }

    // ========== Driver → LarAgent ==========

    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];
        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ];
    }

    public function extractToolCalls(array $response): array
    {
        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];

        return array_map(fn($tc) => new ToolCall(
            $tc['id'],
            $tc['function']['name'],
            $tc['function']['arguments']
        ), $toolCalls);
    }

    public function extractContent(array $response): string
    {
        return $response['choices'][0]['message']['content'] ?? '';
    }

    public function extractFinishReason(array $response): string
    {
        return $response['choices'][0]['finish_reason'] ?? 'stop';
    }
}
```

```php
// src/Drivers/Gemini/GeminiMessageFormatter.php
class GeminiMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver ==========

    public function formatMessage(MessageInterface $message): array
    {
        return match (true) {
            $message instanceof ToolCallMessage => $this->formatToolCallMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof SystemMessage,
            $message instanceof DeveloperMessage => $this->formatSystemMessage($message),
            default => throw new \InvalidArgumentException('Unknown message type'),
        };
    }

    public function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            // Skip system/developer messages - handled separately via extractSystemInstruction()
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                continue;
            }
            $formatted[] = $this->formatMessage($message);
        }

        return $formatted;
    }

    /**
     * Extract system instruction from messages (Gemini-specific).
     * Gemini doesn't support system messages in contents[], they go in systemInstruction.
     */
    public function extractSystemInstruction(array $messages): ?array
    {
        $systemParts = [];

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                $systemParts[] = ['text' => $message->getContentAsString()];
            }
        }

        return !empty($systemParts) ? ['parts' => $systemParts] : null;
    }

    public function formatTools(array $tools): array
    {
        return [
            'functionDeclarations' => array_map(fn(ToolInterface $tool) => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->getProperties(),
                    'required' => $tool->getRequired(),
                ],
            ], $tools),
        ];
    }

    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        $parts = array_map(fn(ToolCall $tc) => [
            'functionCall' => [
                'name' => $tc->getToolName(),
                'args' => json_decode($tc->getArguments(), true),
            ]
        ], $message->getToolCalls()->all());

        return ['role' => 'model', 'parts' => $parts];
    }

    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $content = $message->getContent();
        return [
            'role' => 'user',
            'parts' => [[
                'functionResponse' => [
                    'name' => $content->tool_name ?? 'function',
                    'response' => [
                        'name' => $content->tool_name ?? 'function',
                        'content' => json_decode((string) $content, true) ?? (string) $content,
                    ],
                ],
            ]],
        ];
    }

    protected function formatUserMessage(UserMessage $message): array
    {
        return [
            'role' => 'user',
            'parts' => [['text' => $message->getContentAsString()]],
        ];
    }

    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        return [
            'role' => 'model',
            'parts' => [['text' => $message->getContentAsString()]],
        ];
    }

    // ========== Driver → LarAgent ==========

    public function extractUsage(array $response): array
    {
        $usage = $response['usageMetadata'] ?? [];
        return [
            'prompt_tokens' => $usage['promptTokenCount'] ?? 0,
            'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
            'total_tokens' => $usage['totalTokenCount'] ?? 0,
        ];
    }

    public function extractToolCalls(array $response): array
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    'call_' . uniqid(),  // Gemini doesn't provide IDs
                    $fc['name'],
                    json_encode($fc['args'] ?? [])
                );
            }
        }

        return $toolCalls;
    }

    public function extractContent(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }

        return '';
    }

    public function extractFinishReason(array $response): string
    {
        $reason = $response['candidates'][0]['finishReason'] ?? 'STOP';

        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION' => 'content_filter',
            default => strtolower($reason),
        };
    }
}
```

```php
// src/Drivers/Anthropic/ClaudeMessageFormatter.php
class ClaudeMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver ==========

    public function formatMessage(MessageInterface $message): array
    {
        return match (true) {
            $message instanceof ToolCallMessage => $this->formatToolCallMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            // System/Developer handled via extractSystemInstruction()
            $message instanceof SystemMessage,
            $message instanceof DeveloperMessage => throw new \InvalidArgumentException(
                'System messages should be extracted via extractSystemInstruction()'
            ),
            default => throw new \InvalidArgumentException('Unknown message type'),
        };
    }

    public function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            // Skip system/developer messages - handled separately
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                continue;
            }
            $formatted[] = $this->formatMessage($message);
        }

        return $formatted;
    }

    /**
     * Extract system instruction from messages.
     * Claude uses a single 'system' field (string) at payload root.
     */
    public function extractSystemInstruction(array $messages): ?string
    {
        $systemParts = [];

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                $systemParts[] = $message->getContentAsString();
            }
        }

        return !empty($systemParts) ? implode("\n", $systemParts) : null;
    }

    public function formatTools(array $tools): array
    {
        return array_map(fn(ToolInterface $tool) => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'input_schema' => [  // Claude uses 'input_schema', not 'parameters'
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ],
        ], $tools);
    }

    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        // Claude tool calls are content blocks with type 'tool_use'
        $content = array_map(fn(ToolCall $tc) => [
            'type' => 'tool_use',
            'id' => $tc->getId(),
            'name' => $tc->getToolName(),
            'input' => json_decode($tc->getArguments(), true),  // Object, not JSON string
        ], $message->getToolCalls()->all());

        return [
            'role' => 'assistant',
            'content' => $content,
        ];
    }

    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        // Claude tool results are user messages with 'tool_result' content blocks
        $content = $message->getContent();
        return [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => $content->tool_call_id,
                    'content' => (string) $content,
                ],
            ],
        ];
    }

    protected function formatUserMessage(UserMessage $message): array
    {
        // Claude requires content as array of parts
        return [
            'role' => 'user',
            'content' => $this->formatContentParts($message),
        ];
    }

    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        return [
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Format content as array of parts (Claude always requires array format).
     */
    protected function formatContentParts(MessageInterface $message): array
    {
        $content = $message->getContent();

        // If MessageContent (array of parts), format each
        if ($content instanceof MessageContent) {
            $parts = [];
            foreach ($content->all() as $part) {
                if ($part instanceof TextContent) {
                    $parts[] = ['type' => 'text', 'text' => (string) $part];
                } elseif ($part instanceof ImageContent) {
                    $parts[] = [
                        'type' => 'image',
                        'source' => ['type' => 'url', 'url' => $part->getUrl()],
                    ];
                }
            }
            return $parts;
        }

        // Simple text content
        return [['type' => 'text', 'text' => $message->getContentAsString()]];
    }

    // ========== Driver → LarAgent ==========

    public function extractUsage(array $response): array
    {
        // Claude uses 'input_tokens' and 'output_tokens'
        $usage = $response['usage'] ?? [];
        return [
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        ];
    }

    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        $content = $response['content'] ?? [];

        foreach ($content as $item) {
            if (($item['type'] ?? null) === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_encode($item['input'] ?? [])  // Convert object to JSON string
                );
            }
        }

        return $toolCalls;
    }

    public function extractContent(array $response): string
    {
        $content = $response['content'] ?? [];

        foreach ($content as $item) {
            if (($item['type'] ?? null) === 'text') {
                return $item['text'] ?? '';
            }
        }

        return '';
    }

    public function extractFinishReason(array $response): string
    {
        $reason = $response['stop_reason'] ?? 'end_turn';

        return match ($reason) {
            'end_turn' => 'stop',
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            default => $reason,
        };
    }
}
```

### Phase 6: Update Driver to Use Formatter

The driver becomes simpler - it only deals with arrays and delegates transformation to the formatter.

```php
// src/Drivers/OpenAi/BaseOpenAiDriver.php
abstract class BaseOpenAiDriver extends LlmDriver
{
    protected MessageFormatter $formatter;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);
        $this->formatter = new OpenAiMessageFormatter();
    }

    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        // Format LarAgent messages → driver-specific arrays
        $formattedMessages = $this->formatter->formatMessages($messages);

        // Build payload (driver works with arrays)
        $payload = $this->preparePayload($formattedMessages, $options);

        // Make API call (returns array)
        $response = $this->client->chat()->create($payload);
        $responseArray = $response->toArray();

        // Extract data from response using formatter
        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $usage = $this->formatter->extractUsage($responseArray);

        if ($finishReason === 'tool_calls') {
            // Extract tool calls and create LarAgent ToolCallMessage
            $toolCalls = $this->formatter->extractToolCalls($responseArray);
            return new ToolCallMessage($toolCalls, ['usage' => $usage]);
        }

        // Extract content and create LarAgent AssistantMessage
        $content = $this->formatter->extractContent($responseArray);
        return new AssistantMessage($content, ['usage' => $usage]);
    }

    protected function preparePayload(array $formattedMessages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->getSettings()['model'] ?? 'gpt-4o-mini',
            'messages' => $formattedMessages,
        ];

        // Add tools if registered
        if (!empty($this->tools)) {
            $payload['tools'] = $this->formatter->formatTools($this->getRegisteredTools());
        }

        // Add other options...
        return array_merge($payload, $this->getConfig());
    }

    // REMOVED: toolCallsToMessage() - not needed anymore
    // REMOVED: toolResultToMessage() - not needed anymore
    // Formatter handles all message type conversions automatically
}
```

```php
// src/Drivers/Gemini/GeminiDriver.php
class GeminiDriver extends LlmDriver
{
    protected MessageFormatter $formatter;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);
        $this->formatter = new GeminiMessageFormatter();
    }

    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        // Format LarAgent messages → Gemini-specific structure
        $formattedMessages = $this->formatter->formatMessages($messages);

        // Build payload
        $payload = [
            'contents' => $formattedMessages,
        ];

        // Add system instruction if present (Gemini-specific)
        $systemInstruction = $this->formatter->extractSystemInstruction($messages);
        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        // Add tools if registered
        if (!empty($this->tools)) {
            $payload['tools'] = [$this->formatter->formatTools($this->getRegisteredTools())];
        }

        // Make API call
        $responseArray = $this->makeRequest($payload);

        // Extract data from response
        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $usage = $this->formatter->extractUsage($responseArray);

        if ($finishReason === 'tool_calls' || !empty($this->formatter->extractToolCalls($responseArray))) {
            $toolCalls = $this->formatter->extractToolCalls($responseArray);
            return new ToolCallMessage($toolCalls, ['usage' => $usage]);
        }

        $content = $this->formatter->extractContent($responseArray);
        return new AssistantMessage($content, ['usage' => $usage]);
    }

    // REMOVED: toolCallsToMessage() - not needed anymore
    // REMOVED: toolResultToMessage() - not needed anymore
}
```

#### Key Benefits of This Approach

1. **Driver is clean**: Only deals with arrays and HTTP
2. **No `toolResultToMessage()`**: Create `ToolResultMessage` object, formatter converts it
3. **No `toolCallsToMessage()`**: Create `ToolCallMessage` object, formatter converts it
4. **Testable**: Formatter can be unit tested with mock data
5. **Consistent**: All drivers follow the same pattern

### Phase 7: Update ChatHistory to Store Message Objects

```php
// ChatHistory stores MessageInterface objects, not arrays
class ChatHistory
{
    /** @var MessageInterface[] */
    protected array $messages = [];

    public function addMessage(MessageInterface $message): void
    {
        $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    // For serialization to storage
    public function toArray(): array
    {
        return array_map(fn(MessageInterface $m) => $m->toArray(), $this->messages);
    }
}
```

---

## Migration Strategy

### Backward Compatibility

1. **Keep old methods as deprecated**:

    ```php
    /** @deprecated Use MessageFormatter::formatMessage() instead */
    public function toolCallsToMessage(array $toolCalls): array
    {
        trigger_error('toolCallsToMessage() is deprecated', E_USER_DEPRECATED);
        // ... old implementation
    }
    ```

2. **Support both array and object messages during transition**:
    ```php
    public function formatMessages(array $messages): array
    {
        return array_map(function($msg) {
            if ($msg instanceof MessageInterface) {
                return $this->formatMessage($msg);
            }
            // Legacy array format
            return $msg;
        }, $messages);
    }
    ```

### Breaking Changes

**✅ PLAN COMPLETED - All phases implemented**

#### Implemented Breaking Changes:

1. `Message::create()` removed - use specific message constructors (`Message::user()`, etc.)
2. `Message::fromArray()` removed - use `MessageArray` for polymorphic deserialization
3. `toolCallsToMessage()` deprecated in drivers - formatter handles this via `formatMessage(ToolCallMessage)`
4. `toolResultToMessage()` deprecated in drivers - formatter handles this via `formatMessage(ToolResultMessage)`
5. `$content` property is always DataModel type - constructors accept `string` but convert internally
6. `ToolCall` now extends `DataModel` with nested `ToolCallFunction`
7. Base `Message` now has `$id` (auto-generated) and `$extras` properties
8. `ToolResultMessage` constructor requires `toolName` as third parameter

#### Backward Compatibility Note:

-   Drivers still accept raw arrays in `sendMessage()` for backward compatibility with existing code

---

## File Changes Summary

| File                                               | Action | Description                                                                       |
| -------------------------------------------------- | ------ | --------------------------------------------------------------------------------- |
| `src/Core/Abstractions/DataModelArray.php`         | MODIFY | Add `findItem()`, `getItem()`, `setItem()`, `hasItem()`, `removeItem()`           |
| `src/Core/Contracts/Message.php`                   | MODIFY | Update interface with `getId()`, DataModel content                                |
| `src/ToolCall.php`                                 | MODIFY | Extend DataModel, implement ToolCallInterface                                     |
| `src/Messages/DataModels/ToolCallFunction.php`     | CREATE | Nested DataModel for function details                                             |
| `src/Messages/DataModels/ToolCallArray.php`        | CREATE | DataModelArray for tool calls                                                     |
| `src/Messages/DataModels/ToolResultContent.php`    | CREATE | DataModel for tool result content                                                 |
| `src/Messages/DataModels/Content/TextContent.php`  | MODIFY | Add `__toString()` method                                                         |
| `src/Core/Abstractions/Message.php`                | MODIFY | Add `$id`, `$extras`, remove `$content`, make abstract                            |
| `src/Messages/UserMessage.php`                     | MODIFY | Content as `TextContent\|MessageContent\|null`                                    |
| `src/Messages/AssistantMessage.php`                | MODIFY | Content as `?TextContent`                                                         |
| `src/Messages/SystemMessage.php`                   | MODIFY | Content as `?TextContent`, add constructor                                        |
| `src/Messages/DeveloperMessage.php`                | MODIFY | Content as `?TextContent`, add constructor                                        |
| `src/Messages/ToolCallMessage.php`                 | MODIFY | Use `ToolCallArray`, no content                                                   |
| `src/Messages/ToolResultMessage.php`               | MODIFY | Content as `?ToolResultContent`                                                   |
| `src/Messages/DataModels/MessageArray.php`         | MODIFY | Already exists - used for polymorphic deserialization                             |
| `src/Message.php`                                  | MODIFY | Make pure factory, remove inheritance                                             |
| `src/Core/Contracts/MessageFormatter.php`          | CREATE | Interface: formatMessage, formatMessages, formatTools, extract\*                  |
| `src/Core/Abstractions/MessageFormatter.php`       | CREATE | Base class with default implementations                                           |
| `src/Drivers/OpenAi/OpenAiMessageFormatter.php`    | CREATE | OpenAI formatting + extraction                                                    |
| `src/Drivers/Gemini/GeminiMessageFormatter.php`    | CREATE | Gemini formatting + extraction                                                    |
| `src/Drivers/Anthropic/ClaudeMessageFormatter.php` | CREATE | Claude formatting + extraction                                                    |
| `src/Drivers/Groq/GroqMessageFormatter.php`        | CREATE | Groq formatting + extraction (OpenAI-compatible)                                  |
| `src/Core/Abstractions/LlmDriver.php`              | MODIFY | Add MessageFormatter property, remove `toolCallsToMessage`, `toolResultToMessage` |
| `src/Drivers/OpenAi/BaseOpenAiDriver.php`          | MODIFY | Use formatter, remove `toolCallsToMessage`, `toolResultToMessage`                 |
| `src/Drivers/Gemini/GeminiDriver.php`              | MODIFY | Use formatter, remove `toolCallsToMessage`, `toolResultToMessage`                 |

---

## Testing Strategy

1. **Unit tests for ToolCall (extended with DataModel)**:

    - `toArray()` outputs canonical format
    - `fromArray()` reconstructs correctly
    - Implements `ToolCallInterface`

2. **Unit tests for Content DataModels**:

    - `TextContent` serializes to `{ type: 'text', text: '...' }`
    - `ToolResultContent` serializes correctly
    - `__toString()` returns expected text

3. **Unit tests for Message classes**:

    - Each message type has correct content type
    - `getId()` returns unique ID
    - `getContentAsString()` works correctly
    - `toArray()` includes `id` field (for storage)
    - `toSchema()` excludes `id` field (for API)

4. **Unit tests for `$extras` functionality**:

    - `getExtras()`/`setExtras()` work correctly
    - `getExtra()`/`setExtra()` for individual fields
    - `hasExtra()` returns correct boolean
    - `removeExtra()` deletes field
    - Unknown fields from `fromArray()` go to `$extras`
    - Stored extras from `fromArray()` merge correctly
    - `toArray()` includes `extras` when not empty
    - Extras excluded from `toSchema()`

5. **Unit tests for DataModelArray enhancements**:

    - `findItem('id', 'msg_123')` returns correct index
    - `getItem('id', 'msg_123')` returns correct item
    - `setItem('id', 'msg_123', $newItem)` replaces correctly
    - `hasItem('id', 'msg_123')` returns correct boolean
    - `removeItem('id', 'msg_123')` removes correctly

6. **Unit tests for MessageFormatter implementations**:

    - `formatMessage()` handles each message type correctly per driver
    - `formatMessages()` iterates correctly
    - `formatTools()` produces correct driver-specific tool format
    - `extractUsage()` parses usage from response correctly
    - `extractToolCalls()` returns correct ToolCall objects
    - `extractContent()` extracts text content correctly
    - `extractFinishReason()` normalizes finish reason correctly
    - Round-trip: format → send → extract produces expected results

7. **Integration tests**:
    - Full conversation with tool calls works
    - Chat history can be saved and restored
    - Message retrieval by ID works
    - Switching drivers mid-conversation works (with canonical history)

---

## Open Questions

1. **Should we support driver-switching mid-conversation?**

    - If yes, canonical format is essential
    - If no, we could allow driver-specific extensions
    - **ANSWER**: Yes, canonical format is the whole point

2. **How to handle driver-specific features?**

    - Gemini's `parts` structure
    - Claude's `content` array with types
    - **ANSWER**: MessageFormatter transforms at send/receive time

3. **Should `MessageContent` (for images/audio) need driver-specific formatting?**

    - Currently already uses DataModel
    - **ANSWER**: Yes, each driver's MessageFormatter handles this

4. **Timeline for deprecation?**

    - How long to support legacy array format?
    - When to remove deprecated methods?

5. **Should `id` be exposed in `toArrayWithMeta()`?**
    - Currently metadata is separate
    - ID is for internal tracking, not API format
    - **ANSWER**: Yes, include in `toArrayWithMeta()` for storage

---

## Relationship to Other Plans

### Driver Config DTO Plan

-   Message standardization + Config DTOs together eliminate all array-based approaches
-   Both use the same transformation pattern (canonical DTO → driver-specific format)

### ChatHistoryStorage Plan

-   Depends on Message standardization being complete
-   Uses `MessageArray` for polymorphic deserialization
-   Uses message `id` for individual message operations

---

## Next Steps

1. ✅ Review and approve this plan
2. ✅ Create Driver Config DTO plan (separate document)
3. Implement Phase 1 (ToolCall extends DataModel + DataModelArray enhancements)
4. Implement Phase 1.2 (Update MessageInterface)
5. Implement Phase 2 (Message refactoring with ID and DataModel content)
6. Implement Phase 3 (Message.php facade)
7. Implement Phase 4-6 (MessageFormatter)
8. Implement Phase 7 (ChatHistory updates)
9. Update tests
10. Update documentation
