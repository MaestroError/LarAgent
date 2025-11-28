# Chat History Storage Implementation Plan

## Overview

This document outlines the plan to implement a new `ChatHistoryStorage` class that leverages the existing `Storage` abstraction (`LarAgent\Context\Abstract\Storage`) while preserving the ability to reconstruct messages with their original types (e.g., `UserMessage`, `AssistantMessage`, `ToolCallMessage`, etc.).

**Key Design Decisions:**

-   Uses Laravel's `class_basename()` for type identification
-   Nested discriminator arrays with `matchesArray()` static method for ambiguous types
-   Configuration via DTO (`ChatHistoryConfig`)
-   Hook methods settable from Agent class
-   Token-based auto-truncation before save
-   Returns typed `MessageArray` instead of plain arrays

_Note: code examples given here are conceptual, real implementation is up to you_

---

## Current State Analysis

### Message Types

Located in `src/Messages/`:

| Class                      | Role        | Content Type                          | Special Properties                                                |
| -------------------------- | ----------- | ------------------------------------- | ----------------------------------------------------------------- |
| `UserMessage`              | `user`      | Array (with type: text, image, audio) | Can have images/audio via `withImage()`, `withAudio()`            |
| `AssistantMessage`         | `assistant` | String or Array                       | Custom `__toString()` logic                                       |
| `SystemMessage`            | `system`    | String                                | Simple text message                                               |
| `DeveloperMessage`         | `developer` | String                                | Simple text message                                               |
| `ToolCallMessage`          | `assistant` | String (empty) + toolCalls            | Extends `AssistantMessage`, has `$toolCalls` array                |
| `ToolResultMessage`        | `tool`      | Built from array                      | Has dynamic properties                                            |
| `StreamedAssistantMessage` | `assistant` | String                                | Extends `AssistantMessage`, has streaming state (not for storage) |

### Message Base Class (`src/Core/Abstractions/Message.php`)

-   Extends `DataModel` (already supports `toArray()` and `fromArray()`)
-   Has `$role`, `$content`, `$metadata`, `$dynamicProperties`
-   `buildFromArray()` method populates from array data (**TO BE DEPRECATED**)
-   `toArray()` returns public properties + dynamic properties
-   `toArrayWithMeta()` includes metadata

**Note:** Include metadata by default in toArray (We should deprecate storeMeta property)

### New Storage Abstraction (`src/Context/Abstract/Storage.php`)

-   Uses `DataModelArray` for typed collections
-   Supports polymorphic deserialization via `discriminator()`
-   Has `SessionIdentity` with scope isolation
-   Lazy loading, dirty tracking, multi-driver support

---

## The Problem

When messages are stored as arrays and rebuilt using `Message::fromArray()`, the original class type is lost:

```php
// Original
$message = new UserMessage("Hello");  // instanceof UserMessage ✓

// After storage round-trip
$rebuilt = Message::fromArray($message->toArray());  // instanceof Message ✗
// NOT instanceof UserMessage!
```

---

## Solution: Polymorphic Message Array with Nested Discriminator

### Discriminator Strategy

Use `role` as the primary discriminator. When multiple classes share the same discriminator value, use a nested array and resolve via `matchesArray()` static method on each class.

**`allowedModels()` Structure:**

```php
public static function allowedModels(): array
{
    return [
        'user' => UserMessage::class,
        'system' => SystemMessage::class,
        'developer' => DeveloperMessage::class,
        'tool' => ToolResultMessage::class,
        // Nested array for ambiguous discriminator values
        'assistant' => [
            AssistantMessage::class,
            ToolCallMessage::class,
        ],
    ];
}
```

**Resolution Logic:**

1. Get discriminator value from item (e.g., `role`)
2. If mapped to a single class → use that class
3. If mapped to an array → call `matchesArray($item)` on each class
4. First class that returns `true` wins (order doesn't matter with proper `matchesArray` logic)

---

## Implementation Steps

### Phase 1: Update DataModelArray for Nested Discriminator

#### 1.1 Update `fill()` Method in DataModelArray

```php
// src/Core/Abstractions/DataModelArray.php

public function fill(array $attributes): static
{
    $this->items = [];
    $allowedModels = static::allowedModels();
    $discriminator = $this->discriminator();

    foreach ($attributes as $item) {
        if ($item instanceof DataModelContract) {
            $this->validateAllowedModel($item);
            $this->items[] = $item;
            continue;
        }

        if (!is_array($item)) {
            throw new InvalidArgumentException("Item must be an array or DataModel instance.");
        }

        $targetClass = $this->resolveTargetClass($item, $allowedModels, $discriminator);
        $this->items[] = $targetClass::fromArray($item);
    }

    return $this;
}

protected function resolveTargetClass(array $item, array $allowedModels, string $discriminator): string
{
    if (!isset($item[$discriminator])) {
        throw new InvalidArgumentException("Missing discriminator field '{$discriminator}'.");
    }

    $discriminatorValue = $item[$discriminator];

    if (!isset($allowedModels[$discriminatorValue])) {
        throw new InvalidArgumentException("Unknown discriminator value: {$discriminatorValue}");
    }

    $target = $allowedModels[$discriminatorValue];

    // Single class - use directly
    if (is_string($target)) {
        return $target;
    }

    // Array of classes - resolve via matchesArray()
    if (is_array($target)) {
        return $this->resolveFromCandidates($target, $item);
    }

    throw new InvalidArgumentException("Invalid model mapping for: {$discriminatorValue}");
}

protected function resolveFromCandidates(array $candidates, array $item): string
{
    foreach ($candidates as $class) {
        if (method_exists($class, 'matchesArray') && $class::matchesArray($item)) {
            return $class;
        }
    }

    // If no matchesArray method or none matched, use first as default
    return $candidates[0];
}

protected function validateAllowedModel(DataModelContract $item): void
{
    $allowedModels = static::allowedModels();
    $isAllowed = false;

    foreach ($allowedModels as $modelOrArray) {
        $classes = is_array($modelOrArray) ? $modelOrArray : [$modelOrArray];
        foreach ($classes as $class) {
            if ($item instanceof $class) {
                $isAllowed = true;
                break 2;
            }
        }
    }

    if (!$isAllowed) {
        throw new InvalidArgumentException("Item is not an instance of an allowed model.");
    }
}
```

---

### Phase 2: Message Classes Updates

#### 2.1 Deprecate `buildFromArray()`

```php
// src/Core/Abstractions/Message.php

/**
 * @deprecated Use fromArray() instead
 */
public function buildFromArray(array $data): self
{
    @trigger_error('buildFromArray() is deprecated, use fromArray() instead', E_USER_DEPRECATED);
    return static::fromArray($data);
}
```

#### 2.2 Override `fromArray()` in Message Base

```php
// src/Core/Abstractions/Message.php

public static function fromArray(array $data): static
{
    static::validateRole($data['role'] ?? '');

    $instance = parent::fromArray($data);

    if (isset($data['metadata'])) {
        $instance->metadata = $data['metadata'];
    }

    // Handle dynamic properties
    foreach ($data as $key => $value) {
        if (!property_exists($instance, $key) && $key !== 'metadata') {
            $instance->__set($key, $value);
        }
    }

    return $instance;
}
```

#### 2.3 Add `#[ExcludeFromSchema]` Attribute and Update Message Classes

**PHP Property Override Rules:**

-   Child classes **cannot change the type** of a parent property
-   If parent has **no default**, child **can add** a default
-   If parent has a default, child **can change** but **cannot remove** it

**Strategy:** Remove defaults from `Message` base class for `$role` and `$content`, allowing children to:

1. Add fixed default for `$role` (e.g., `'user'`, `'assistant'`)
2. Add `= null` default for `$content` only where content may be empty (e.g., `ToolCallMessage`)

##### 2.3.1 Create the Attribute

```php
// src/Attributes/ExcludeFromSchema.php

namespace LarAgent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ExcludeFromSchema
{
}
```

##### 2.3.2 Update `DataModel::generateSchema()` to Respect the Attribute

```php
// src/Core/Abstractions/DataModel.php

use LarAgent\Attributes\ExcludeFromSchema;

public static function generateSchema(): array
{
    $config = static::getCachedConfig();
    $schema = [
        'type' => 'object',
        'properties' => [],
        'required' => $config['required'],
    ];

    foreach ($config['properties'] as $name => $propConfig) {
        // Skip properties marked with #[ExcludeFromSchema]
        if ($propConfig['excludeFromSchema']) {
            continue;
        }

        $propertySchema = static::getPropertySchemaFromConfig($propConfig);
        if ($propertySchema) {
            $schema['properties'][$name] = $propertySchema;
        }
    }

    // Also remove excluded properties from required
    $schema['required'] = array_values(array_filter(
        $schema['required'],
        fn($name) => !($config['properties'][$name]['excludeFromSchema'] ?? false)
    ));

    if (empty($schema['required'])) {
        unset($schema['required']);
    }

    return $schema;
}
```

##### 2.3.3 Update `getCachedConfig()` to Cache the Attribute

```php
// In getCachedConfig() method, add:

$excludeAttributes = $property->getAttributes(ExcludeFromSchema::class);

$config['properties'][$name] = [
    'reflection' => $property,
    'type' => $type,
    'description' => $description,
    'excludeFromSchema' => !empty($excludeAttributes),  // Add this
];
```

##### 2.3.4 Update Message Base Class (Remove Defaults)

```php
// src/Core/Abstractions/Message.php

abstract class Message extends DataModel implements MessageInterface
{
    #[Desc('The role of the message sender')]
    public string|Role $role;  // NO DEFAULT - children will add their fixed value

    #[Desc('The content of the message')]
    public null|string|MessageContent $content;  // NO DEFAULT - children decide

    // ... rest of class
}
```

##### 2.3.5 Message Class Property Overrides

Children add `#[ExcludeFromSchema]` on `$role` (fixed value) and add defaults where appropriate:

```php
// src/Messages/UserMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class UserMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'user';  // Fixed value, excluded from schema

    #[Desc('The content of the message as an array of content parts (text, image, audio)')]
    public null|string|MessageContent $content;  // Required - no default

    // ... rest of class
}
```

```php
// src/Messages/AssistantMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class AssistantMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'assistant';  // Fixed value

    #[Desc('The text content of the assistant response')]
    public null|string|MessageContent $content;  // Required - no default

    // ... rest of class
}
```

```php
// src/Messages/SystemMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class SystemMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'system';  // Fixed value

    #[Desc('The system instruction content')]
    public null|string|MessageContent $content;  // Required - no default
}
```

```php
// src/Messages/DeveloperMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class DeveloperMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'developer';  // Fixed value

    #[Desc('The developer instruction content')]
    public null|string|MessageContent $content;  // Required - no default
}
```

```php
// src/Messages/ToolCallMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class ToolCallMessage extends AssistantMessage implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'assistant';  // Fixed value

    #[ExcludeFromSchema]
    public null|string|MessageContent $content = null;  // Optional - has default null

    #[Desc('Array of tool calls requested by the assistant')]
    public array $toolCalls;  // Required - no default

    // ... rest of class
}
```

```php
// src/Messages/ToolResultMessage.php

use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;

class ToolResultMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'tool';  // Fixed value

    #[Desc('The result content from tool execution')]
    public null|string|MessageContent $content;  // Required - no default

    #[Desc('The ID of the tool call this result responds to')]
    public string $tool_call_id;  // Required - no default

    // ... rest of class
}
```

**Example Schema Output (with `#[ExcludeFromSchema]`):**

```php
// UserMessage::generateSchema() returns:
[
    'type' => 'object',
    'properties' => [
        'content' => [
            'description' => 'The content of the message as an array of content parts (text, image, audio)',
        ]
    ],
    'required' => ['content']
]
// Note: 'role' is NOT in schema - marked with #[ExcludeFromSchema]

// ToolCallMessage::generateSchema() returns:
[
    'type' => 'object',
    'properties' => [
        'toolCalls' => [
            'type' => 'array',
            'description' => 'Array of tool calls requested by the assistant'
        ]
    ],
    'required' => ['toolCalls']
]
// Note: 'role' and 'content' are NOT in schema - both marked with #[ExcludeFromSchema]

// ToolResultMessage::generateSchema() returns:
[
    'type' => 'object',
    'properties' => [
        'content' => [
            'description' => 'The result content from tool execution'
        ],
        'tool_call_id' => [
            'type' => 'string',
            'description' => 'The ID of the tool call this result responds to'
        }
    ],
    'required' => ['content', 'tool_call_id']
]
// Note: 'role' is NOT in schema - marked with #[ExcludeFromSchema]
```

**Benefits of this approach:**

1. **Explicit control** - Use `#[ExcludeFromSchema]` to mark fixed/internal properties
2. **Leverages PHP rules** - Parent has no defaults, children add fixed defaults for `$role`
3. **Flexible `$content`** - Only `ToolCallMessage` has `$content = null` (optional), others require it
4. **Clean API** - Schema only shows what users actually need to provide

#### 2.4 Add `matchesArray()` to Ambiguous Message Types

```php
// src/Messages/ToolCallMessage.php

public static function matchesArray(array $data): bool
{
    return !empty($data['tool_calls']);
}

public static function fromArray(array $data): static
{
    $toolCalls = $data['tool_calls'] ?? [];
    $metadata = $data['metadata'] ?? [];

    unset($data['metadata']);

    return new static($toolCalls, $data, $metadata);
}
```

```php
// src/Messages/AssistantMessage.php

public static function matchesArray(array $data): bool
{
    // Matches when it's assistant role WITHOUT tool_calls
    return empty($data['tool_calls']);
}

public static function fromArray(array $data): static
{
    $content = $data['content'] ?? '';
    $metadata = $data['metadata'] ?? [];
    return new static($content, $metadata);
}
```

#### 2.5 Add `fromArray()` to Other Message Classes

```php
// src/Messages/UserMessage.php
public static function fromArray(array $data): static
{
    $content = $data['content'] ?? '';
    $metadata = $data['metadata'] ?? [];

    if (is_array($content)) {
        $instance = new static('');
        $instance->content = new MessageContent($content);
        $instance->metadata = $metadata;
        return $instance;
    }

    return new static($content, $metadata);
}

// src/Messages/SystemMessage.php
public static function fromArray(array $data): static
{
    $content = $data['content'] ?? '';
    $metadata = $data['metadata'] ?? [];
    return new static($content, $metadata);
}

// src/Messages/DeveloperMessage.php
public static function fromArray(array $data): static
{
    $content = $data['content'] ?? '';
    $metadata = $data['metadata'] ?? [];
    return new static($content, $metadata);
}

// src/Messages/ToolResultMessage.php
public static function fromArray(array $data): static
{
    $metadata = $data['metadata'] ?? [];
    unset($data['metadata']);
    return new static($data, $metadata);
}
```

---

### Phase 3: Create MessageArray

```php
// src/Messages/DataModels/MessageArray.php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;

class MessageArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'user' => UserMessage::class,
            'system' => SystemMessage::class,
            'developer' => DeveloperMessage::class,
            'tool' => ToolResultMessage::class,
            'assistant' => [
                ToolCallMessage::class,  // Check first (has specific condition)
                AssistantMessage::class, // Default fallback
            ],
        ];
    }

    public function discriminator(): string
    {
        return 'role';
    }
}
```

---

### Phase 4: Create ChatHistoryConfig DTO

```php
// src/Core/DTO/ChatHistoryConfig.php

namespace LarAgent\Context\DTOs;

class ChatHistoryConfig
{
    public function __construct(
        public readonly int $contextWindow = 60000,
        public readonly int $reservedForCompletion = 1000,
        public readonly bool $storeMeta = true,
        public readonly ?callable $onBeforeSave = null,
        public readonly ?callable $onAfterLoad = null,
        public readonly ?callable $onBeforeAdd = null,
        public readonly ?callable $onAfterAdd = null,
        public readonly ?callable $truncationCallback = null,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            contextWindow: $config['context_window'] ?? 60000,
            reservedForCompletion: $config['reserved_for_completion'] ?? 1000,
            storeMeta: $config['store_meta'] ?? true,
            onBeforeSave: $config['on_before_save'] ?? null,
            onAfterLoad: $config['on_after_load'] ?? null,
            onBeforeAdd: $config['on_before_add'] ?? null,
            onAfterAdd: $config['on_after_add'] ?? null,
            truncationCallback: $config['truncation_callback'] ?? null,
        );
    }
}
```

---

### Phase 5: Create ChatHistoryStorage

```php
// src/Context/ChatHistoryStorage.php

namespace LarAgent\Context;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\DTOs\ChatHistoryConfig;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Core\Contracts\Message as MessageContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;

class ChatHistoryStorage extends Storage
{
    protected ChatHistoryConfig $config;

    public function __construct(
        array $driversConfig,
        SessionIdentityContract $identity,
        ChatHistoryConfig|array $config = []
    ) {
        $this->config = $config instanceof ChatHistoryConfig
            ? $config
            : ChatHistoryConfig::fromArray($config);

        parent::__construct($driversConfig, $identity);
    }

    protected function getDataModelClass(): string
    {
        return MessageArray::class;
    }

    protected function getStoragePrefix(): string
    {
        return 'chat_history';
    }

    // ========================================
    // Public API
    // ========================================

    /**
     * Add a message to the history
     */
    public function addMessage(MessageContract $message): void
    {
        // Hook: before add
        if ($this->config->onBeforeAdd) {
            ($this->config->onBeforeAdd)($message, $this);
        }

        $this->add($message);

        // Hook: after add
        if ($this->config->onAfterAdd) {
            ($this->config->onAfterAdd)($message, $this);
        }
    }

    /**
     * Get all messages as typed MessageArray
     */
    public function getMessages(): DataModelArrayContract
    {
        return $this->get();
    }

    /**
     * Get messages formatted for API calls (plain array)
     */
    public function toApiFormat(): array
    {
        return $this->get()->toArray();
    }

    /**
     * Get the last message
     */
    public function getLastMessage(): ?MessageContract
    {
        return $this->getLast();
    }

    /**
     * Get the configuration
     */
    public function getConfig(): ChatHistoryConfig
    {
        return $this->config;
    }

    // ========================================
    // Token Management
    // ========================================

    public function exceedsContextWindow(int $tokens): bool
    {
        return $tokens > ($this->config->contextWindow - $this->config->reservedForCompletion);
    }

    public function getContextWindow(): int
    {
        return $this->config->contextWindow;
    }

    // ========================================
    // Overridden Storage Methods
    // ========================================

    public function save(): void
    {
        if ($this->dirty) {
            // Hook: before save
            if ($this->config->onBeforeSave) {
                ($this->config->onBeforeSave)($this);
            }

            // Check tokens and truncate if needed
            $this->checkAndTruncate();

            $this->writeItems();
            $this->dirty = false;
        }
    }

    protected function load(): void
    {
        parent::load();

        // Hook: after load
        if ($this->config->onAfterLoad) {
            ($this->config->onAfterLoad)($this);
        }
    }

    protected function writeItems(): void
    {
        if ($this->config->storeMeta) {
            $data = $this->items->map(fn($message) => $message->toArrayWithMeta());
        } else {
            $data = $this->items->toArray();
        }

        $this->storageManager->save($this->identity, $data);
    }

    // ========================================
    // Token-based Truncation
    // ========================================

    protected function checkAndTruncate(): void
    {
        $lastMessage = $this->items->last();
        if (!$lastMessage) {
            return;
        }

        $meta = $lastMessage->getMetadata();
        $totalTokens = $meta['usage']['total_tokens'] ?? null;

        if ($totalTokens === null) {
            return;
        }

        if ($this->exceedsContextWindow($totalTokens)) {
            $this->truncateMessages($totalTokens);
        }
    }

    protected function truncateMessages(int $currentTokens): void
    {
        if ($this->config->truncationCallback) {
            // Custom truncation logic
            ($this->config->truncationCallback)($this->items, $currentTokens, $this->config);
        } else {
            // Default truncation
            $this->defaultTruncation($currentTokens);
        }
    }

    protected function defaultTruncation(int $currentTokens): void
    {
        $targetTokens = $this->config->contextWindow - $this->config->reservedForCompletion;

        // Estimate tokens per message (rough average)
        $messageCount = $this->items->count();
        if ($messageCount <= 1) {
            return;
        }

        $tokensPerMessage = $currentTokens / $messageCount;
        $tokensToRemove = $currentTokens - $targetTokens;
        $messagesToRemove = (int) ceil($tokensToRemove / $tokensPerMessage);

        // Remove oldest non-system messages
        $removed = 0;
        $indicesToRemove = [];

        foreach ($this->items as $index => $message) {
            if ($removed >= $messagesToRemove) {
                break;
            }
            if ($message->getRole() !== 'system') {
                $indicesToRemove[] = $index;
                $removed++;
            }
        }

        // Remove in reverse order to maintain indices
        foreach (array_reverse($indicesToRemove) as $index) {
            $this->items->remove($index);
        }
    }
}
```

---

### Phase 7: Testing

#### 7.1 MessageArray Tests

```php
// tests/LarAgent/Messages/MessageArrayTest.php

test('MessageArray reconstructs UserMessage from role discriminator', function () {
    $data = [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]
    ];

    $messageArray = new MessageArray($data);

    expect($messageArray[0])->toBeInstanceOf(UserMessage::class);
});

test('MessageArray reconstructs ToolCallMessage when tool_calls present', function () {
    $data = [
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => '123']]]
    ];

    $messageArray = new MessageArray($data);

    expect($messageArray[0])->toBeInstanceOf(ToolCallMessage::class);
});

test('MessageArray reconstructs AssistantMessage when no tool_calls', function () {
    $data = [
        ['role' => 'assistant', 'content' => 'Hello!']
    ];

    $messageArray = new MessageArray($data);

    expect($messageArray[0])->toBeInstanceOf(AssistantMessage::class);
});

test('MessageArray handles mixed message types', function () {
    $data = [
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
        ['role' => 'assistant', 'content' => 'Hello!'],
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => '1']]],
        ['role' => 'tool', 'tool_call_id' => '1', 'content' => 'result'],
    ];

    $messageArray = new MessageArray($data);

    expect($messageArray[0])->toBeInstanceOf(SystemMessage::class);
    expect($messageArray[1])->toBeInstanceOf(UserMessage::class);
    expect($messageArray[2])->toBeInstanceOf(AssistantMessage::class);
    expect($messageArray[3])->toBeInstanceOf(ToolCallMessage::class);
    expect($messageArray[4])->toBeInstanceOf(ToolResultMessage::class);
});
```

#### 7.2 ChatHistoryStorage Tests

```php
// tests/LarAgent/Context/ChatHistoryStorageTest.php

test('ChatHistoryStorage returns MessageArray from getMessages', function () {
    $storage = new ChatHistoryStorage(
        [new InMemoryStorage()],
        new SessionIdentity('agent', 'chat')
    );

    $storage->addMessage(new UserMessage('Hello'));

    expect($storage->getMessages())->toBeInstanceOf(MessageArray::class);
    expect($storage->getMessages()[0])->toBeInstanceOf(UserMessage::class);
});

test('ChatHistoryStorage calls hooks', function () {
    $beforeSaveCalled = false;
    $afterLoadCalled = false;

    $config = new ChatHistoryConfig(
        onBeforeSave: function () use (&$beforeSaveCalled) {
            $beforeSaveCalled = true;
        },
        onAfterLoad: function () use (&$afterLoadCalled) {
            $afterLoadCalled = true;
        },
    );

    $driver = new InMemoryStorage();
    $identity = new SessionIdentity('agent', 'chat');

    $storage = new ChatHistoryStorage([$driver], $identity, $config);
    $storage->addMessage(new UserMessage('Hello'));
    $storage->save();

    expect($beforeSaveCalled)->toBeTrue();

    // Create new instance to trigger load
    $storage2 = new ChatHistoryStorage([$driver], $identity, $config);
    $storage2->read();

    expect($afterLoadCalled)->toBeTrue();
});

test('ChatHistoryStorage truncates when exceeding context window', function () {
    $config = new ChatHistoryConfig(
        contextWindow: 1000,
        reservedForCompletion: 200,
    );

    $storage = new ChatHistoryStorage(
        [new InMemoryStorage()],
        new SessionIdentity('agent', 'chat'),
        $config
    );

    // Add messages
    $storage->addMessage(new SystemMessage('System prompt'));
    $storage->addMessage(new UserMessage('Hello'));

    // Add message with high token count in metadata
    $assistantMsg = new AssistantMessage('Response');
    $assistantMsg->setMetadata(['usage' => ['total_tokens' => 1500]]);
    $storage->addMessage($assistantMsg);

    $storage->save();

    // Should have truncated some messages
    expect($storage->count())->toBeLessThan(3);
    // System message should be preserved
    expect($storage->getMessages()[0])->toBeInstanceOf(SystemMessage::class);
});

test('ChatHistoryStorage uses custom truncation callback', function () {
    $customTruncationCalled = false;

    $config = new ChatHistoryConfig(
        contextWindow: 1000,
        truncationCallback: function ($messages, $tokens, $config) use (&$customTruncationCalled) {
            $customTruncationCalled = true;
            // Custom logic here
        },
    );

    $storage = new ChatHistoryStorage(
        [new InMemoryStorage()],
        new SessionIdentity('agent', 'chat'),
        $config
    );

    $storage->addMessage(new UserMessage('Hello'));
    $assistantMsg = new AssistantMessage('Response');
    $assistantMsg->setMetadata(['usage' => ['total_tokens' => 1500]]);
    $storage->addMessage($assistantMsg);

    $storage->save();

    expect($customTruncationCalled)->toBeTrue();
});
```

---

## File Changes Summary

### New Files

| File                                                | Purpose                                     |
| --------------------------------------------------- | ------------------------------------------- |
| `src/Attributes/ExcludeFromSchema.php`              | Attribute to exclude properties from schema |
| `src/Messages/DataModels/MessageArray.php`          | Polymorphic message collection              |
| `src/Context/ChatHistoryStorage.php`                | New storage implementation                  |
| `src/Context/DTOs/ChatHistoryConfig.php`            | Configuration DTO with hooks                |
| `tests/LarAgent/Messages/MessageArrayTest.php`      | MessageArray tests                          |
| `tests/LarAgent/Context/ChatHistoryStorageTest.php` | Storage tests                               |

### Modified Files

| File                                       | Changes                                                                                           |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| `src/Core/Abstractions/DataModel.php`      | Update `generateSchema()` to respect `#[ExcludeFromSchema]`, update `getCachedConfig()`           |
| `src/Core/Abstractions/DataModelArray.php` | Add nested discriminator support, `resolveFromCandidates()`                                       |
| `src/Core/Abstractions/Message.php`        | Remove defaults from `$role`/`$content`, deprecate `buildFromArray()`                             |
| `src/Messages/UserMessage.php`             | Add `#[ExcludeFromSchema]` on `role` with default, add `fromArray()`                              |
| `src/Messages/AssistantMessage.php`        | Add `#[ExcludeFromSchema]` on `role` with default, add `fromArray()`, `matchesArray()`            |
| `src/Messages/SystemMessage.php`           | Add `#[ExcludeFromSchema]` on `role` with default, add `fromArray()`                              |
| `src/Messages/DeveloperMessage.php`        | Add `#[ExcludeFromSchema]` on `role` with default, add `fromArray()`                              |
| `src/Messages/ToolCallMessage.php`         | Add `#[ExcludeFromSchema]` on `role`/`content` with defaults, add `fromArray()`, `matchesArray()` |
| `src/Messages/ToolResultMessage.php`       | Add `#[ExcludeFromSchema]` on `role` with default, add `fromArray()`                              |

---

## Implementation Order

1. 🔲 Phase 1: Update DataModelArray for nested discriminator support
2. 🔲 Phase 2: Update Message classes with `fromArray()`, `matchesArray()`, property overrides
3. 🔲 Phase 3: Create MessageArray
4. 🔲 Phase 4: Create ChatHistoryConfig DTO
5. 🔲 Phase 5: Create ChatHistoryStorage
6. 🔲 Phase 6: Document hook registration from Agent
7. 🔲 Phase 7: Write tests
8. 🔲 Optional: Migration adapter and deprecation notices for old ChatHistory
