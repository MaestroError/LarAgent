# Adding and Registering Custom Storage Drivers

This guide explains how developers can create and register custom storage drivers in LarAgent.

## Storage Driver Contract

All storage drivers must implement the `StorageDriver` contract:

```php
namespace LarAgent\Context\Contracts;

interface StorageDriver
{
    /**
     * Read data from memory/storage
     *
     * @return array|null Returns null if no data found, empty array if cleared
     */
    public function readFromMemory(SessionIdentity $identity): ?array;

    /**
     * Write data to memory/storage
     *
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool;

    /**
     * Remove data from memory/storage
     *
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool;

    /**
     * Create a new driver instance.
     */
    public static function make(?array $config = null): static;
}
```

## Creating a Custom Storage Driver

### Step 1: Extend the Abstract Base

```php
<?php

namespace App\Storage\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class RedisStorageDriver extends StorageDriver
{
    protected string $prefix;
    protected int $ttl;
    
    public function __construct(string $prefix = 'laragent:', int $ttl = 86400)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }
    
    /**
     * Read data from Redis.
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $key = $this->prefix . $identity->getKey();
        
        $data = \Redis::get($key);
        
        if ($data === null || $data === false) {
            return null;
        }
        
        $decoded = json_decode($data, true);
        
        return is_array($decoded) ? $decoded : null;
    }
    
    /**
     * Write data to Redis with TTL.
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $key = $this->prefix . $identity->getKey();
        
        try {
            \Redis::setex($key, $this->ttl, json_encode($data));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove data from Redis.
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $key = $this->prefix . $identity->getKey();
        
        try {
            \Redis::del($key);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Factory method.
     */
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static();
        }
        
        return new static(
            prefix: $config['prefix'] ?? 'laragent:',
            ttl: $config['ttl'] ?? 86400
        );
    }
}
```

### Step 2: MongoDB Example

```php
<?php

namespace App\Storage\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use MongoDB\Client;

class MongoStorageDriver extends StorageDriver
{
    protected Client $client;
    protected string $database;
    protected string $collection;
    
    public function __construct(
        ?Client $client = null,
        string $database = 'laragent',
        string $collection = 'storage'
    ) {
        $this->client = $client ?? new Client(config('database.connections.mongodb.dsn'));
        $this->database = $database;
        $this->collection = $collection;
    }
    
    protected function getCollection()
    {
        return $this->client
            ->selectDatabase($this->database)
            ->selectCollection($this->collection);
    }
    
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $document = $this->getCollection()->findOne([
            '_id' => $identity->getKey()
        ]);
        
        if ($document === null) {
            return null;
        }
        
        return $document['data'] ?? null;
    }
    
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            $this->getCollection()->updateOne(
                ['_id' => $identity->getKey()],
                [
                    '$set' => [
                        'data' => $data,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime()
                    ]
                ],
                ['upsert' => true]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $this->getCollection()->deleteOne([
                '_id' => $identity->getKey()
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static();
        }
        
        return new static(
            database: $config['database'] ?? 'laragent',
            collection: $config['collection'] ?? 'storage'
        );
    }
}
```

### Step 3: DynamoDB Example

```php
<?php

namespace App\Storage\Drivers;

use Aws\DynamoDb\DynamoDbClient;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class DynamoDbStorageDriver extends StorageDriver
{
    protected DynamoDbClient $client;
    protected string $tableName;
    
    public function __construct(?DynamoDbClient $client = null, string $tableName = 'laragent_storage')
    {
        $this->client = $client ?? new DynamoDbClient([
            'region' => config('services.dynamodb.region', 'us-east-1'),
            'version' => 'latest',
        ]);
        $this->tableName = $tableName;
    }
    
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        try {
            $result = $this->client->getItem([
                'TableName' => $this->tableName,
                'Key' => [
                    'pk' => ['S' => $identity->getKey()]
                ]
            ]);
            
            if (!isset($result['Item']['data'])) {
                return null;
            }
            
            return json_decode($result['Item']['data']['S'], true);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            $this->client->putItem([
                'TableName' => $this->tableName,
                'Item' => [
                    'pk' => ['S' => $identity->getKey()],
                    'data' => ['S' => json_encode($data)],
                    'updated_at' => ['N' => (string) time()]
                ]
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $this->client->deleteItem([
                'TableName' => $this->tableName,
                'Key' => [
                    'pk' => ['S' => $identity->getKey()]
                ]
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static();
        }
        
        return new static(
            tableName: $config['table_name'] ?? 'laragent_storage'
        );
    }
}
```

## Registering Custom Drivers

### Method 1: Per-Agent Property

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use App\Storage\Drivers\RedisStorageDriver;

class MyAgent extends Agent
{
    protected $instructions = 'You are a helpful assistant.';
    
    // Use custom driver for chat history
    protected $history = [
        RedisStorageDriver::class,
    ];
    
    // Use custom driver for general storage
    protected $storage = [
        RedisStorageDriver::class,
    ];
}
```

### Method 2: Global Configuration

```php
// config/laragent.php

return [
    // Default for all storages
    'default_storage' => [
        \App\Storage\Drivers\RedisStorageDriver::class,
    ],
    
    // Default for chat history specifically
    'default_history_storage' => [
        \App\Storage\Drivers\RedisStorageDriver::class,
        \LarAgent\Context\Drivers\FileStorage::class, // Fallback
    ],
    
    // Default for usage tracking
    'default_usage_storage' => [
        \App\Storage\Drivers\MongoStorageDriver::class,
    ],
];
```

### Method 3: Per-Provider Configuration

```php
// config/laragent.php

return [
    'providers' => [
        'default' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            
            // Provider-specific storage
            'history' => [
                \App\Storage\Drivers\RedisStorageDriver::class,
            ],
            'storage' => [
                \App\Storage\Drivers\MongoStorageDriver::class,
            ],
            'usage_storage' => [
                \App\Storage\Drivers\DynamoDbStorageDriver::class,
            ],
        ],
    ],
];
```

### Method 4: Runtime Configuration

```php
// Use custom driver at runtime
$agent = SupportAgent::make();
$agent->setChatHistory(new ChatHistoryStorage(
    $agent->context()->getIdentity(),
    [new RedisStorageDriver('custom_prefix:', 3600)]
));
```

## Creating Custom Storages (Not Just Drivers)

If you need entirely new storage types (not just different backends, but different data and/or logic), create a custom Storage class:

### Step 1: Define DataModel

The DataModel defines the structure of a single data item. It acts as a typed schema for your stored data, ensuring type safety and providing serialization/deserialization capabilities.

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;

class ConversationSummary extends DataModel
{
    public string $summary;
    public int $messageCount;
    public string $createdAt;
    public ?string $topics = null;
}
```

### Step 2: Define DataModelArray

The DataModelArray serves as a typed collection container for your DataModels. It specifies which model types can be stored and handles polymorphic data through the discriminator method.

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class ConversationSummaryArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'default' => ConversationSummary::class,
        ];
    }
    
    public function discriminator(): string
    {
        return 'type';
    }
}
```

### Step 3: Create Storage Class

The Storage class provides the business logic layer for interacting with your data. It wraps the underlying storage drivers and exposes domain-specific methods for adding, retrieving, and manipulating your data models.

```php
<?php

namespace App\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use App\DataModels\ConversationSummary;
use App\DataModels\ConversationSummaryArray;

class SummaryStorage extends Storage
{
    /**
     * Define the DataModelArray class for this storage.
     */
    protected function getDataModelClass(): string
    {
        return ConversationSummaryArray::class;
    }
    
    /**
     * Define the storage prefix for key isolation.
     */
    public static function getStoragePrefix(): string
    {
        return 'summaries';
    }
    
    /**
     * Add a conversation summary.
     */
    public function addSummary(string $summary, int $messageCount, ?string $topics = null): ConversationSummary
    {
        $item = ConversationSummary::fromArray([
            'summary' => $summary,
            'messageCount' => $messageCount,
            'createdAt' => now()->toIso8601String(),
            'topics' => $topics,
        ]);
        
        $this->add($item);
        
        return $item;
    }
    
    /**
     * Get all summaries.
     */
    public function getSummaries(): ConversationSummaryArray
    {
        return $this->get();
    }
    
    /**
     * Get the latest summary.
     */
    public function getLatestSummary(): ?ConversationSummary
    {
        return $this->getLast();
    }
}
```

### Step 4: Register with Context

Finally, integrate your custom storage into an Agent by registering it with the Context. This makes your storage accessible throughout the agent's lifecycle and ensures proper initialization with the correct identity.

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use App\Context\Storages\SummaryStorage;

class SummaryAgent extends Agent
{
    protected $instructions = 'You are a helpful assistant.';
    
    protected SummaryStorage $summaryStorage;
    
    protected function setupChatHistory(): void
    {
        parent::setupChatHistory();
        
        // Register custom storage with context
        $this->summaryStorage = $this->context()->make(SummaryStorage::class);
    }
    
    public function summaries(): SummaryStorage
    {
        return $this->summaryStorage;
    }
}
```

> **Note:** Using `$this->context()->make(SummaryStorage::class)` will use the Agent's default `$storage` drivers. If you need different drivers for this specific storage, instantiate it directly with custom drivers:

```php
use App\Storage\Drivers\RedisStorageDriver;

protected function setupChatHistory(): void
{
    parent::setupChatHistory();
    
    // Register with custom drivers instead of agent defaults
    $this->summaryStorage = new SummaryStorage(
        $this->context()->getIdentity(),
        [new RedisStorageDriver('summaries:', 7200)]
    );
}
```

## Driver Chain Pattern

LarAgent supports multiple drivers with fallback behavior:

```php
// config/laragent.php

'default_history_storage' => [
    \App\Storage\Drivers\RedisStorageDriver::class,    // Primary: fast
    \LarAgent\Context\Drivers\CacheStorage::class,     // Fallback 1
    \LarAgent\Context\Drivers\FileStorage::class,      // Fallback 2: persistent
],
```

**Read behavior**: Tries drivers in order until one returns data.
**Write behavior**: Writes to ALL drivers.
**Remove behavior**: Removes from ALL drivers.

This ensures data consistency and provides redundancy.

## Testing Custom Drivers

LarAgent uses Pest for testing. Here's an example of how to test a custom Redis storage driver:

```php
<?php

use App\Storage\Drivers\RedisStorageDriver;
use LarAgent\Context\SessionIdentity;

// Helper to create test identity
function createTestIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

test('RedisStorageDriver: reads null when no data exists', function () {
    $driver = new RedisStorageDriver('test:', 60);
    $identity = createTestIdentity('TestAgent', 'nonexistent-' . uniqid());

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('RedisStorageDriver: writes and reads data correctly', function () {
    $driver = new RedisStorageDriver('test:', 60);
    $identity = createTestIdentity('TestAgent', 'test-session');

    $data = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
    ];

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();

    $retrieved = $driver->readFromMemory($identity);
    expect($retrieved)->toBe($data);

    // Cleanup
    $driver->removeFromMemory($identity);
});

test('RedisStorageDriver: removes data correctly', function () {
    $driver = new RedisStorageDriver('test:', 60);
    $identity = createTestIdentity('TestAgent', 'test-session');

    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->not->toBeNull();

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('RedisStorageDriver: isolates data by identity', function () {
    $driver = new RedisStorageDriver('test:', 60);
    $identity1 = createTestIdentity('Agent1', 'chat1');
    $identity2 = createTestIdentity('Agent2', 'chat2');

    $driver->writeToMemory($identity1, ['data' => 'one']);
    $driver->writeToMemory($identity2, ['data' => 'two']);

    expect($driver->readFromMemory($identity1))->toBe(['data' => 'one']);
    expect($driver->readFromMemory($identity2))->toBe(['data' => 'two']);

    // Cleanup
    $driver->removeFromMemory($identity1);
    $driver->removeFromMemory($identity2);
});

test('RedisStorageDriver: factory method creates configured instance', function () {
    $driver = RedisStorageDriver::make([
        'prefix' => 'custom:',
        'ttl' => 120,
    ]);

    expect($driver)->toBeInstanceOf(RedisStorageDriver::class);
});
```

## Real-World Scenario: Redundant Storage with Fallback

### Requirements
- Primary storage in Redis for speed
- Backup to S3 for persistence
- Automatic failover

### Implementation

```php
<?php

namespace App\Storage\Drivers;

use Aws\S3\S3Client;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class S3StorageDriver extends StorageDriver
{
    protected S3Client $client;
    protected string $bucket;
    protected string $prefix;
    
    public function __construct(
        ?S3Client $client = null,
        string $bucket = 'laragent-storage',
        string $prefix = 'conversations/'
    ) {
        $this->client = $client ?? new S3Client([
            'region' => config('services.s3.region'),
            'version' => 'latest',
        ]);
        $this->bucket = $bucket;
        $this->prefix = $prefix;
    }
    
    protected function getKey(SessionIdentity $identity): string
    {
        return $this->prefix . $identity->getKey() . '.json';
    }
    
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getKey($identity),
            ]);
            
            $body = (string) $result['Body'];
            return json_decode($body, true);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }
            throw $e;
        }
    }
    
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getKey($identity),
                'Body' => json_encode($data, JSON_PRETTY_PRINT),
                'ContentType' => 'application/json',
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getKey($identity),
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public static function make(?array $config = null): static
    {
        if ($config === null) {
            return new static();
        }
        
        return new static(
            bucket: $config['bucket'] ?? 'laragent-storage',
            prefix: $config['prefix'] ?? 'conversations/'
        );
    }
}
```

### Configuration

```php
// config/laragent.php

return [
    'default_history_storage' => [
        // Primary: Redis (fast)
        \App\Storage\Drivers\RedisStorageDriver::class,
        // Backup: S3 (persistent)
        \App\Storage\Drivers\S3StorageDriver::class,
    ],
];
```

This setup provides:
1. Fast reads from Redis
2. Automatic S3 backup on every write
3. S3 fallback if Redis fails
4. Durable persistence for disaster recovery
