# Context Storage Drivers

LarAgent provides several storage drivers for persisting chat history and context. Each driver has its own use case and configuration options.

## Available Drivers

| Driver | Use Case | Persistence | Performance |
|--------|----------|-------------|-------------|
| `InMemoryStorage` | Testing, single-request processing | None | Fastest |
| `SessionStorage` | Web requests, user sessions | Session lifetime | Fast |
| `CacheStorage` | Temporary storage with TTL | Configurable | Fast |
| `FileStorage` | Simple file-based persistence | Permanent | Moderate |
| `SimpleEloquentStorage` | Database (JSON blob) | Permanent | Moderate |
| `EloquentStorage` | Database (normalized rows) | Permanent | Best for querying |

---

## InMemoryStorage

Stores data in PHP memory. Data is lost after the request ends. Ideal for testing or single-request agents.

### Constructor

```php
new InMemoryStorage()
```

No configuration options.

### Usage

```php
use LarAgent\Context\Drivers\InMemoryStorage;

$storage = new InMemoryStorage();
```

---

## SessionStorage

Uses Laravel's session to store data. Perfect for web applications where context should persist across user requests.

### Constructor

```php
new SessionStorage()
```

No configuration options. Uses Laravel's default session configuration.

### Usage

```php
use LarAgent\Context\Drivers\SessionStorage;

$storage = new SessionStorage();
```

> **Note:** Requires an active Laravel session. Not suitable for CLI or queue workers.

---

## CacheStorage

Uses Laravel's cache system. Supports different cache stores and provides flexible persistence.

### Constructor

```php
new CacheStorage(?string $store = null)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$store` | `string\|null` | `null` | Cache store name (e.g., `'redis'`, `'memcached'`). Uses default cache store if `null`. |

### Usage

```php
use LarAgent\Context\Drivers\CacheStorage;

// Use default cache store
$storage = new CacheStorage();

// Use specific cache store
$storage = new CacheStorage('redis');
```

---

## FileStorage

Stores data as JSON files on disk using Laravel's filesystem.

### Constructor

```php
new FileStorage(?string $disk = null, string $folder = 'laragent_storage')
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$disk` | `string\|null` | `null` | Storage disk name. Uses `config('filesystems.default')` if `null`. |
| `$folder` | `string` | `'laragent_storage'` | Folder path within the disk where files are stored. |

### Usage

```php
use LarAgent\Context\Drivers\FileStorage;

// Use default disk and folder
$storage = new FileStorage();

// Use specific disk
$storage = new FileStorage('local');

// Use specific disk and custom folder
$storage = new FileStorage('s3', 'chat_history');
```

---

## SimpleEloquentStorage

Stores all context data as a JSON blob in a single database row. Simple setup, good for basic use cases.

### Constructor

```php
new SimpleEloquentStorage(?string $model = null)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$model` | `string\|null` | `null` | Eloquent model class name. Defaults to `LaragentStorage::class`. |

### Usage

```php
use LarAgent\Context\Drivers\SimpleEloquentStorage;

// Use default model
$storage = new SimpleEloquentStorage();

// Use custom model
$storage = new SimpleEloquentStorage(CustomStorage::class);
```

### Default Table Schema

```php
Schema::create('laragent_storage', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->json('data');
    $table->timestamps();
});
```

### Custom Model Example

```php
<?php

namespace App\Models;

use LarAgent\Context\Models\LaragentStorage;

class CustomStorage extends LaragentStorage
{
    protected $table = 'my_custom_storage';
    
    protected $fillable = [
        'key',
        'data',
        'user_id', // Additional field
    ];
    
    protected $casts = [
        'data' => 'array',
    ];
    
    // Add relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

---

## EloquentStorage

Stores each message as a separate database row. Best for applications that need to query individual messages or require advanced database features.

### Constructor

```php
new EloquentStorage(?string $model = null)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$model` | `string\|null` | `null` | Eloquent model class name. Defaults to `LaragentMessage::class`. |

### Additional Methods

```php
// Customize column names
$storage->setKeyColumn('session_key');     // Column for session identifier
$storage->setPositionColumn('position');   // Column for message ordering
```

### Usage

```php
use LarAgent\Context\Drivers\EloquentStorage;

// Use default model
$storage = new EloquentStorage();

// Use custom model
$storage = new EloquentStorage(CustomMessage::class);

// With custom column names
$storage = (new EloquentStorage(CustomMessage::class))
    ->setKeyColumn('chat_session_id')
    ->setPositionColumn('order');
```

### Default Table Schema

```php
Schema::create('laragent_messages', function (Blueprint $table) {
    $table->id();
    $table->string('session_key')->index();
    $table->integer('position');
    
    // Core message fields
    $table->string('role');
    $table->json('content')->nullable();
    $table->string('message_uuid')->nullable();
    $table->timestamp('message_created')->nullable();
    
    // Tool-related fields
    $table->json('tool_calls')->nullable();
    $table->string('tool_call_id')->nullable();
    
    // Usage statistics
    $table->json('usage')->nullable();
    
    // Additional data
    $table->json('metadata')->nullable();
    $table->json('extras')->nullable();
    
    $table->timestamps();
});
```

### Custom Model Example

```php
<?php

namespace App\Models;

use LarAgent\Context\Models\LaragentMessage;

class ChatMessage extends LaragentMessage
{
    protected $table = 'chat_messages';
    
    protected $fillable = [
        'session_key',
        'position',
        'role',
        'content',
        'message_uuid',
        'message_created',
        'tool_calls',
        'tool_call_id',
        'usage',
        'metadata',
        'extras',
        // Custom fields
        'user_id',
        'agent_name',
        'tokens_used',
    ];
    
    protected $casts = [
        'position' => 'integer',
        'content' => 'array',
        'tool_calls' => 'array',
        'usage' => 'array',
        'metadata' => 'array',
        'extras' => 'array',
        'tokens_used' => 'integer',
    ];
    
    // Add relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    // Add scopes
    public function scopeByAgent($query, string $agentName)
    {
        return $query->where('agent_name', $agentName);
    }
    
    // Add custom methods
    public function getTotalTokens(): int
    {
        return $this->tokens_used ?? 0;
    }
}
```

### Extended Model with Additional Features

```php
<?php

namespace App\Models;

use LarAgent\Context\Models\LaragentMessage;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditableMessage extends LaragentMessage
{
    use SoftDeletes;
    
    protected $table = 'auditable_messages';
    
    protected $fillable = [
        'session_key',
        'position',
        'role',
        'content',
        'message_uuid',
        'message_created',
        'tool_calls',
        'tool_call_id',
        'usage',
        'metadata',
        'extras',
        // Audit fields
        'ip_address',
        'user_agent',
        'tenant_id',
    ];
    
    protected static function booted()
    {
        // Automatically set audit fields
        static::creating(function ($message) {
            $message->ip_address = request()->ip();
            $message->user_agent = request()->userAgent();
            $message->tenant_id = app('tenant')->id ?? null;
        });
    }
    
    // Multi-tenancy scope
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
```

---

## Configuration in Agent Class

Override the `createChatHistory` method in your agent to use a specific storage driver:

```php
<?php

namespace App\Agents;

use LarAgent\Agent;
use LarAgent\History\ChatHistory;
use LarAgent\Context\Drivers\EloquentStorage;
use App\Models\ChatMessage;

class MyAgent extends Agent
{
    protected function createChatHistory(): ChatHistory
    {
        $storage = new EloquentStorage(ChatMessage::class);
        
        return new ChatHistory(
            $storage,
            $this->historySettings()
        );
    }
}
```

---

## Summary

| Driver | Constructor | Configuration |
|--------|-------------|---------------|
| `InMemoryStorage` | `new InMemoryStorage()` | None |
| `SessionStorage` | `new SessionStorage()` | None |
| `CacheStorage` | `new CacheStorage($store)` | Cache store name |
| `FileStorage` | `new FileStorage($disk, $folder)` | Disk name, folder path |
| `SimpleEloquentStorage` | `new SimpleEloquentStorage($model)` | Custom model class |
| `EloquentStorage` | `new EloquentStorage($model)` | Custom model class, column names |
