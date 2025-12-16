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

If you need entirely new storage types (not just different backends), create a custom Storage class:

### Step 1: Define DataModel

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

```php
<?php

namespace Tests\Unit\Storage;

use Tests\TestCase;
use App\Storage\Drivers\RedisStorageDriver;
use LarAgent\Context\SessionIdentity;

class RedisStorageDriverTest extends TestCase
{
    protected RedisStorageDriver $driver;
    protected SessionIdentity $identity;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->driver = new RedisStorageDriver('test:', 60);
        $this->identity = new SessionIdentity(
            agentName: 'TestAgent',
            chatName: 'test-session'
        );
    }
    
    protected function tearDown(): void
    {
        // Clean up
        $this->driver->removeFromMemory($this->identity);
        parent::tearDown();
    }
    
    public function test_write_and_read()
    {
        $data = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];
        
        // Write
        $result = $this->driver->writeToMemory($this->identity, $data);
        $this->assertTrue($result);
        
        // Read
        $retrieved = $this->driver->readFromMemory($this->identity);
        $this->assertEquals($data, $retrieved);
    }
    
    public function test_read_nonexistent_returns_null()
    {
        $identity = new SessionIdentity(
            agentName: 'TestAgent',
            chatName: 'nonexistent-' . uniqid()
        );
        
        $result = $this->driver->readFromMemory($identity);
        $this->assertNull($result);
    }
    
    public function test_remove()
    {
        $data = [['role' => 'user', 'content' => 'Test']];
        
        $this->driver->writeToMemory($this->identity, $data);
        
        // Verify data exists
        $this->assertNotNull($this->driver->readFromMemory($this->identity));
        
        // Remove
        $result = $this->driver->removeFromMemory($this->identity);
        $this->assertTrue($result);
        
        // Verify removed
        $this->assertNull($this->driver->readFromMemory($this->identity));
    }
    
    public function test_factory_method()
    {
        $driver = RedisStorageDriver::make([
            'prefix' => 'custom:',
            'ttl' => 120,
        ]);
        
        $this->assertInstanceOf(RedisStorageDriver::class, $driver);
    }
}
```

## Real-World Scenario: Multi-Region Deployment

### Requirements
- Primary storage in local Redis for speed
- Backup to S3 for cross-region persistence
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
        // Primary: Local Redis (fast)
        \App\Storage\Drivers\RedisStorageDriver::class,
        // Backup: S3 (persistent, cross-region)
        \App\Storage\Drivers\S3StorageDriver::class,
    ],
];
```

This setup provides:
1. Fast reads from local Redis
2. Automatic S3 backup on every write
3. S3 fallback if Redis fails
4. Cross-region persistence for disaster recovery
