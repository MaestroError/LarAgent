# Storage System and DataModel

LarAgent's storage system provides a unified abstraction for persisting data across different backends. DataModels serve as the foundation for type-safe data structures used throughout the system.

## Storage System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Context                             │
│  (Orchestrates multiple storages for an agent)              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   ┌──────────────────┐  ┌──────────────────┐               │
│   │ ChatHistoryStorage│  │  UsageStorage    │  ...          │
│   │ (extends Storage) │  │ (extends Storage)│               │
│   └────────┬─────────┘  └────────┬─────────┘               │
│            │                      │                         │
│   ┌────────▼─────────────────────▼─────────┐               │
│   │             StorageManager              │               │
│   │  (Manages primary + secondary drivers)  │               │
│   └────────┬─────────────────────┬─────────┘               │
│            │                      │                         │
│   ┌────────▼──────┐      ┌───────▼───────┐                 │
│   │Primary Driver │      │Secondary Driver│                 │
│   │ (CacheStorage)│      │ (FileStorage)  │                 │
│   └───────────────┘      └───────────────┘                 │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. Storage (Abstract Base)

The `Storage` abstract class provides the foundation for all storage types:

```php
namespace LarAgent\Context\Abstract;

abstract class Storage implements StorageContract
{
    // Identity for data isolation
    protected SessionIdentityContract $identity;
    
    // Manager handling driver operations
    protected StorageManager $storageManager;
    
    // Cached data items
    protected DataModelArray $items;
    
    // Dirty tracking for efficient saves
    protected bool $dirty = false;
    
    // Lazy loading flag
    protected bool $loaded = false;
    
    // Must be implemented by subclasses
    abstract protected function getDataModelClass(): string;
    abstract public static function getStoragePrefix(): string;
}
```

### 2. StorageDriver (Abstract Base)

Storage drivers implement the actual read/write operations:

```php
namespace LarAgent\Context\Abstract;

abstract class StorageDriver implements StorageInterface
{
    abstract public function readFromMemory(SessionIdentity $identity): ?array;
    abstract public function writeToMemory(SessionIdentity $identity, array $data): bool;
    abstract public function removeFromMemory(SessionIdentity $identity): bool;
}
```

### 3. StorageManager

Manages primary and secondary (fallback) drivers:

```php
$manager = new StorageManager([
    CacheStorage::class,  // Primary - tried first
    FileStorage::class,   // Secondary - fallback if primary fails
]);

// Read: tries primary first, then secondaries
$data = $manager->read($identity);

// Write: writes to ALL drivers
$manager->save($identity, $data);

// Remove: removes from ALL drivers
$manager->remove($identity);
```

### 4. SessionIdentity

Uniquely identifies a storage location:

```php
use LarAgent\Context\SessionIdentity;

$identity = new SessionIdentity(
    agentName: 'MyAgent',
    chatName: 'session-123',
    userId: 'user-456',
    group: 'premium',
    scope: 'chatHistory'  // Added automatically by Storage
);

// Generated key: chatHistory_premium_user-456
$key = $identity->getKey();
```

## Built-in Storage Drivers

### CacheStorage
Uses Laravel's cache system:
```php
use LarAgent\Context\Drivers\CacheStorage;

// Default cache store
$driver = new CacheStorage();

// Specific cache store
$driver = new CacheStorage('redis');
```

### FileStorage
Stores data in JSON files:
```php
use LarAgent\Context\Drivers\FileStorage;

$driver = new FileStorage();
// Stores in: storage/app/laragent/{key}.json
```

### InMemoryStorage
For testing or single-request data:
```php
use LarAgent\Context\Drivers\InMemoryStorage;

$driver = new InMemoryStorage();
// Data lost after request
```

### SessionStorage
Uses Laravel's session system:
```php
use LarAgent\Context\Drivers\SessionStorage;

$driver = new SessionStorage();
// Tied to user's session
```

### EloquentStorage
Full database storage with Eloquent models:
```php
use LarAgent\Context\Drivers\EloquentStorage;

$driver = new EloquentStorage();
// Requires migrations for LaragentMessage, LaragentSessionIdentity, LaragentStorage
```

### SimpleEloquentStorage
Simpler database storage using a single table:
```php
use LarAgent\Context\Drivers\SimpleEloquentStorage;

$driver = new SimpleEloquentStorage();
// Requires laragent_storage table migration
```

## Built-in Storages

### ChatHistoryStorage
Stores conversation messages:
```php
use LarAgent\Context\Storages\ChatHistoryStorage;

$chatHistory = new ChatHistoryStorage($identity, [CacheStorage::class]);
$chatHistory->addMessage($message);
$messages = $chatHistory->getMessages();
```

### UsageStorage
Stores API usage tracking data:
```php
use LarAgent\Usage\UsageStorage;

$usageStorage = new UsageStorage($identity, [CacheStorage::class], 'gpt-4', 'openai');
$usageStorage->addUsage($usageData);
$records = $usageStorage->getUsageRecords();
```

### IdentityStorage
Tracks all registered storage identities:
```php
use LarAgent\Context\Storages\IdentityStorage;

$identityStorage = new IdentityStorage($contextIdentity, $drivers);
$identityStorage->addIdentity($storageIdentity);
$identities = $identityStorage->getIdentities();
```

## DataModel System

DataModels provide type-safe data structures with automatic serialization/deserialization and schema generation.

### Basic DataModel

```php
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class UserProfile extends DataModel
{
    #[Desc('The username')]
    public string $username;

    #[Desc('The email address')]
    public string $email;

    #[Desc('User age')]
    public ?int $age = null;
}
```

### DataModel Features

1. **Automatic Hydration**
   ```php
   $user = UserProfile::fromArray([
       'username' => 'john',
       'email' => 'john@example.com',
       'age' => 30
   ]);
   
   // Or fill existing instance
   $user = new UserProfile();
   $user->fill(['username' => 'john']);
   ```

2. **Serialization**
   ```php
   // To array
   $array = $user->toArray();
   
   // JSON
   $json = json_encode($user);  // Implements JsonSerializable
   ```

3. **Schema Generation**
   ```php
   // Instance method
   $schema = $user->toSchema();
   
   // Static method
   $schema = UserProfile::generateSchema();
   
   // Output:
   // [
   //     'type' => 'object',
   //     'properties' => [
   //         'username' => ['type' => 'string', 'description' => 'The username'],
   //         'email' => ['type' => 'string', 'description' => 'The email address'],
   //         'age' => ['type' => 'integer', 'description' => 'User age'],
   //     ],
   //     'required' => ['username', 'email']
   // ]
   ```

4. **Array Access**
   ```php
   $user['username'] = 'jane';
   echo $user['email'];
   ```

### Constructor Promotion

DataModels support PHP 8's constructor property promotion:

```php
class SearchQuery extends DataModel
{
    public function __construct(
        #[Desc('The search term')]
        public string $query,

        #[Desc('Max results')]
        public int $limit = 10,
    ) {}
}

// Works with both approaches:
$query = new SearchQuery('test', 20);
$query = SearchQuery::fromArray(['query' => 'test', 'limit' => 20]);
```

### DataModelArray

For collections of DataModels:

```php
use LarAgent\Core\Abstractions\DataModelArray;

class UserArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [UserProfile::class];
    }
}

// Usage
$users = new UserArray([
    ['username' => 'john', 'email' => 'john@example.com'],
    ['username' => 'jane', 'email' => 'jane@example.com'],
]);

// Automatically hydrated to UserProfile objects
foreach ($users as $user) {
    echo $user->username;  // $user is UserProfile instance
}
```

### Polymorphic Arrays

For arrays containing different model types:

```php
use LarAgent\Core\Abstractions\DataModelArray;

class MessageContent extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'text' => TextContent::class,
            'image_url' => ImageContent::class,
        ];
    }

    public function discriminator(): string
    {
        return 'type';  // Field used to distinguish types
    }
}

// Usage
$content = new MessageContent([
    ['type' => 'text', 'text' => 'Hello'],
    ['type' => 'image_url', 'image_url' => ['url' => '...']]
]);
```

## Role of DataModel in Storage

DataModels are integral to the storage system:

1. **Type Safety**: Storage operations work with DataModel instances, ensuring type consistency.

2. **Serialization**: DataModels automatically convert to/from arrays for storage drivers.

3. **Collection Management**: DataModelArray provides collection operations (add, remove, filter) with proper typing.

4. **Schema Generation**: Used for structured output validation and tool parameter definitions.

### Storage Data Flow

```
Write:
┌──────────────┐    toArray()    ┌──────────────┐   writeToMemory()   ┌──────────┐
│ DataModel(s) │ ───────────────▶│    Array     │ ──────────────────▶ │  Driver  │
└──────────────┘                 └──────────────┘                     └──────────┘

Read:
┌──────────┐   readFromMemory()   ┌──────────────┐   fromArray()    ┌──────────────┐
│  Driver  │ ───────────────────▶ │    Array     │ ───────────────▶ │ DataModel(s) │
└──────────┘                      └──────────────┘                  └──────────────┘
```

## Performance Considerations

### Lazy Loading
Storage loads data only when first accessed:
```php
$storage = new ChatHistoryStorage($identity, $drivers);
// Data not loaded yet

$messages = $storage->getMessages(); // Data loaded now
```

### Dirty Tracking
Only writes when data has changed:
```php
$storage->addMessage($message);  // Marks dirty
$storage->save();                // Actually writes
$storage->save();                // No-op (not dirty)
```

### Reflection Caching
DataModel uses cached reflection for schema generation:
```php
// First call: uses reflection
$schema1 = UserProfile::generateSchema();

// Subsequent calls: uses cache
$schema2 = UserProfile::generateSchema();
```

### Manual Optimization
For frequently instantiated models, override serialization:
```php
class HighVolumeModel extends DataModel
{
    public string $data;

    // Override for performance (bypasses reflection)
    public static function fromArray(array $attributes): static
    {
        $instance = new static();
        $instance->data = $attributes['data'] ?? '';
        return $instance;
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }
}
```
