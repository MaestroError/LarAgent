# DataModel System

The `DataModel` system in LarAgent provides a robust foundation for handling structured data, ensuring strict typing, automatic validation, serialization, and OpenAPI schema generation. It is designed to be flexible enough for simple DTOs while powerful enough for complex, nested, and polymorphic data structures like AI Message contents.

## Core Concepts

All data models extend the `LarAgent\Core\Abstractions\DataModel` abstract class. This provides:

1.  **Automatic Hydration**: Populate objects from arrays using `fill()` or `fromArray()`.
2.  **Schema Generation**: Automatically generate OpenAPI/JSON Schemas using PHP types and attributes.
3.  **Serialization**: Convert objects back to arrays or JSON.
4.  **Performance**: Uses static runtime caching to minimize Reflection overhead.

## Basic Usage (Minimalistic DTO)

For simple data transfer objects, you can use PHP 8 constructor property promotion. No extra attributes are required.

### Class Definition

```php
use LarAgent\Core\Abstractions\DataModel;

class UserProfile extends DataModel
{
    public function __construct(
        public string $username,
        public string $email,
        public ?int $age = null,
    ) {}
}
```

### Usage

```php
$user = UserProfile::fromArray([
    'username' => 'jdoe',
    'email' => 'jdoe@example.com'
]);

echo $user->username; // 'jdoe'
$schema = $user->toSchema(); // Generates JSON schema automatically
```

## Adding Descriptions (Context for AI)

When your DataModel is intended for use with an LLM (e.g., for structured output or tool arguments), adding descriptions is crucial. You can use the `#[Desc]` attribute with both promoted properties and standard properties.

### With Property Promotion

```php
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class SearchQuery extends DataModel
{
    public function __construct(
        #[Desc('The search term to look for')]
        public string $query,

        #[Desc('The maximum number of results to return')]
        public int $limit = 10,
    ) {}
}
```

### Without Property Promotion

```php
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class SearchQuery extends DataModel
{
    #[Desc('The search term to look for')]
    public string $query;

    #[Desc('The maximum number of results to return')]
    public int $limit = 10;
}
```

## Recommended Usage (Explicit Definition)

For more complex models, especially those mapping to external APIs (like OpenAI), explicit property definitions with `#[Desc]` attributes are recommended. This improves schema generation for the AI.

### Example: ImageContent

This example demonstrates:

-   Public properties instead of constructor promotion.
-   `#[Desc]` attributes for AI context.
-   Nested DataModels (`ImageUrl`).
-   **Performance Optimization**: Overriding `fromArray` and `toArray`.

### Class Definition

DTO-like approach:

```php
use LarAgent\Core\Abstractions\DataModel;

class SessionIdentity
{
    public function __construct(
        public readonly string $agentName,
        public readonly ?string $chatKey = null,
        public readonly ?string $userId = null,
        public readonly ?string $group = null
    ) {}

    /**
     * (Optional) Create instance from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentName: $data['agentName'],
            chatKey: $data['chatKey'] ?? '',
            userId: $data['userId'] ?? '',
            group: $data['group'] ?? ''
        );
    }

    /**
     * (Optional) Convert DM to array
     */
    public function toArray(): array
    {
        return [
            'agentName' => $this->agentName,
            'chatKey' => $this->chatKey,
            'userId' => $this->userId,
            'group' => $this->group,
        ];
    }
}

```

```php
use LarAgent\Messages\DataModels\Content\Parts\ImageUrl;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ImageContent extends DataModel
{
    #[Desc('The type of the content')]
    public string $type = 'image_url';

    #[Desc('The image URL information')]
    public ImageUrl $image_url;

    // Optional: Override for performance (bypasses Reflection)
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'image_url' => $this->image_url->toArray(),
        ];
    }

    // Optional: Override for performance
    public static function fromArray(array $attributes): static
    {
        $instance = new static();
        if (isset($attributes['type'])) {
            $instance->type = $attributes['type'];
        }
        if (isset($attributes['image_url'])) {
            // Handle nested hydration manually
            $instance->image_url = is_array($attributes['image_url'])
                ? ImageUrl::fromArray($attributes['image_url'])
                : $attributes['image_url'];
        }
        return $instance;
    }
}
```

## Polymorphic Arrays (DataModelArray)

To handle lists of different models (e.g., a message containing both Text and Images), extend `DataModelArray`.

### Example: MessageContent

```php
use LarAgent\Core\Abstractions\DataModelArray;

class MessageContent extends DataModelArray
{
    // Define allowed types and their mapping
    public static function allowedModels(): array
    {
        return [
            'text' => TextContent::class,
            'image_url' => ImageContent::class,
        ];
    }

    // Define the field used to distinguish types (default is 'type')
    public function discriminator(): string
    {
        return 'type';
    }
}
```

### Usage

```php
// From array
$content = new MessageContent([
    ['type' => 'text', 'text' => 'Hello'],
    ['type' => 'image_url', 'image_url' => ['url' => '...']]
]);

// Or variadic objects
$content = new MessageContent(
    new TextContent(['text' => 'Hello']),
    new ImageContent(['url' => '...'])
);
```

## Performance & Best Practices

The `DataModel` class uses **Reflection** to inspect properties and types. While we implement **Static Runtime Caching** to mitigate the cost, Reflection is still slower than native code.

### When to Override `fromArray` / `toArray`

You are **not required** to override these methods. The base implementation works perfectly for 90% of use cases.

**Override them ONLY if:**

1.  The model is instantiated frequently (e.g., thousands of times in a loop).
2.  The model is part of a core hot path (like `MessageContent` in a streaming response).
3.  You need custom transformation logic that standard casting doesn't support.

### Summary

1.  **Start Simple**: Use property promotion or simple public properties.
2.  **Add Context**: Use `#[Desc]` if the model is sent to an LLM (for tool definitions or structured output).
3.  **Optimize Later**: Only manually implement `fromArray`/`toArray` if profiling shows a bottleneck.
