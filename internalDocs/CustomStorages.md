# Adding Custom Storages

LarAgent's storage system is designed to be extensible. You can create custom storage types for specific data needs and custom storage drivers for different backends.

## Creating a Custom Storage

A custom storage extends the `Storage` abstract class and defines what data it holds and how it's identified.

### Step 1: Create the Storage Class

```php
<?php

namespace App\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

class PreferencesStorage extends Storage
{
    /**
     * Get the DataModelArray class for items stored.
     * This defines the structure of data in this storage.
     */
    protected function getDataModelClass(): string
    {
        return \App\DataModels\PreferencesArray::class;
    }

    /**
     * Get the storage prefix/scope for isolation.
     * This ensures data from different storages don't collide.
     */
    public static function getStoragePrefix(): string
    {
        return 'preferences';
    }

    /**
     * Add custom methods for your storage type
     */
    public function getPreference(string $key): mixed
    {
        $this->ensureLoaded();
        
        foreach ($this->items as $pref) {
            if ($pref->key === $key) {
                return $pref->value;
            }
        }
        
        return null;
    }

    public function setPreference(string $key, mixed $value): void
    {
        $this->ensureLoaded();
        
        // Remove existing preference with same key
        $this->items = $this->items->filter(fn($p) => $p->key !== $key);
        
        // Add new preference
        $this->add(new \App\DataModels\Preference($key, $value));
    }
}
```

### Step 2: Create the DataModel and DataModelArray

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class Preference extends DataModel
{
    public function __construct(
        #[Desc('The preference key')]
        public string $key = '',

        #[Desc('The preference value')]
        public mixed $value = null
    ) {}
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class PreferencesArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [Preference::class];
    }
}
```

### Step 3: Register and Use the Storage

```php
use App\Storages\PreferencesStorage;

class MyAgent extends Agent
{
    protected function registerCustomStorages(): void
    {
        // Register your custom storage
        $this->context()->make(PreferencesStorage::class);
    }

    public function respond(?string $message = null): string|array|MessageInterface
    {
        // Ensure custom storages are registered
        $this->registerCustomStorages();
        
        return parent::respond($message);
    }

    // Access your storage
    public function userPreferences(): PreferencesStorage
    {
        return $this->context()->getStorage(PreferencesStorage::class);
    }
}

// Usage
$agent = MyAgent::forUserId('user-123');
$agent->userPreferences()->setPreference('theme', 'dark');
$theme = $agent->userPreferences()->getPreference('theme');
```

## Creating a Custom Storage Driver

A custom driver implements the actual persistence mechanism.

### Step 1: Create the Driver Class

```php
<?php

namespace App\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use Illuminate\Support\Facades\Redis;

class RedisStorageDriver extends StorageDriver
{
    protected string $prefix;
    protected ?string $connection;

    public function __construct(string $prefix = 'laragent:', ?string $connection = null)
    {
        $this->prefix = $prefix;
        $this->connection = $connection;
    }

    /**
     * Read data from Redis
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $key = $this->prefix . $identity->getKey();
        
        $data = Redis::connection($this->connection)->get($key);
        
        if ($data === null) {
            return null;
        }

        return json_decode($data, true);
    }

    /**
     * Write data to Redis
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $key = $this->prefix . $identity->getKey();
        
        Redis::connection($this->connection)->set($key, json_encode($data));
        
        return true;
    }

    /**
     * Remove data from Redis
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $key = $this->prefix . $identity->getKey();
        
        Redis::connection($this->connection)->del($key);
        
        return true;
    }
}
```

### Step 2: Use the Custom Driver

```php
// In agent class
class MyAgent extends Agent
{
    protected $storage = [
        \App\Drivers\RedisStorageDriver::class,
        \LarAgent\Context\Drivers\FileStorage::class,  // Fallback
    ];

    protected $history = [
        \App\Drivers\RedisStorageDriver::class,
    ];
}

// Or in config
// config/laragent.php
return [
    'default_storage' => [
        \App\Drivers\RedisStorageDriver::class,
    ],
    'default_history_storage' => [
        \App\Drivers\RedisStorageDriver::class,
        \LarAgent\Context\Drivers\FileStorage::class,
    ],
];
```

## Advanced: Driver with Configuration

```php
<?php

namespace App\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class S3StorageDriver extends StorageDriver
{
    protected string $bucket;
    protected string $prefix;
    protected $client;

    public function __construct(string $bucket = 'laragent', string $prefix = 'storage/')
    {
        $this->bucket = $bucket;
        $this->prefix = $prefix;
        $this->client = \Illuminate\Support\Facades\Storage::disk('s3');
    }

    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $path = $this->prefix . $identity->getKey() . '.json';
        
        if (!$this->client->exists($path)) {
            return null;
        }

        $content = $this->client->get($path);
        return json_decode($content, true);
    }

    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $path = $this->prefix . $identity->getKey() . '.json';
        
        $this->client->put($path, json_encode($data));
        
        return true;
    }

    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $path = $this->prefix . $identity->getKey() . '.json';
        
        $this->client->delete($path);
        
        return true;
    }
}
```

## Registering Drivers in Service Provider

For more complex configuration:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class LarAgentServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register a configured driver instance
        $this->app->singleton(\App\Drivers\RedisStorageDriver::class, function ($app) {
            return new \App\Drivers\RedisStorageDriver(
                prefix: config('app.name') . ':laragent:',
                connection: 'default'
            );
        });
    }
}
```

## Complete Example: State Storage

Here's a complete example of a custom storage for managing agent state:

### StateStorage.php

```php
<?php

namespace App\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use App\DataModels\StateArray;
use App\DataModels\StateEntry;

class StateStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return StateArray::class;
    }

    public static function getStoragePrefix(): string
    {
        return 'state';
    }

    /**
     * Get a state value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();
        
        foreach ($this->items as $entry) {
            if ($entry->key === $key) {
                return $entry->value;
            }
        }
        
        return $default;
    }

    /**
     * Set a state value
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureLoaded();
        
        // Update or add
        $found = false;
        foreach ($this->items as $entry) {
            if ($entry->key === $key) {
                $entry->value = $value;
                $entry->updatedAt = now()->toIso8601String();
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->add(new StateEntry($key, $value, now()->toIso8601String()));
        }
        
        $this->dirty = true;
    }

    /**
     * Check if key exists
     */
    public function has(string $key): bool
    {
        $this->ensureLoaded();
        
        foreach ($this->items as $entry) {
            if ($entry->key === $key) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Remove a key
     */
    public function forget(string $key): void
    {
        $this->ensureLoaded();
        
        $this->items = $this->items->filter(fn($e) => $e->key !== $key);
        $this->dirty = true;
    }

    /**
     * Get all state as array
     */
    public function all(): array
    {
        $this->ensureLoaded();
        
        $result = [];
        foreach ($this->items as $entry) {
            $result[$entry->key] = $entry->value;
        }
        
        return $result;
    }
}
```

### StateEntry.php

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class StateEntry extends DataModel
{
    public function __construct(
        #[Desc('The state key')]
        public string $key = '',

        #[Desc('The state value')]
        public mixed $value = null,

        #[Desc('When the state was last updated')]
        public ?string $updatedAt = null
    ) {}
}
```

### StateArray.php

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class StateArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [StateEntry::class];
    }

    public function filter(callable $callback): static
    {
        $filtered = new static();
        foreach ($this->items as $item) {
            if ($callback($item)) {
                $filtered->add($item);
            }
        }
        return $filtered;
    }
}
```

### Usage in Agent

```php
<?php

namespace App\Agents;

use LarAgent\Agent;
use App\Storages\StateStorage;

class StatefulAgent extends Agent
{
    protected function setupContext(array $driversConfig = []): void
    {
        parent::setupContext($driversConfig);
        
        // Register state storage
        $this->context()->make(StateStorage::class, $driversConfig);
    }

    public function state(): StateStorage
    {
        return $this->context()->getStorage(StateStorage::class);
    }

    public function instructions(): string
    {
        // Use state in instructions
        $stepCount = $this->state()->get('step_count', 0);
        return "You are on step {$stepCount} of the conversation.";
    }

    protected function afterResponse($message): void
    {
        // Track conversation steps
        $count = $this->state()->get('step_count', 0);
        $this->state()->set('step_count', $count + 1);
    }
}

// Usage
$agent = StatefulAgent::forUserId('user-123');
$agent->state()->set('user_name', 'John');
$name = $agent->state()->get('user_name');
$agent->respond('Hello!');
```

## Best Practices

1. **Use meaningful prefixes**: Ensure `getStoragePrefix()` returns a unique, descriptive string.

2. **Implement proper null handling**: Drivers should return `null` when data doesn't exist (not empty array).

3. **Use dirty tracking**: The base `Storage` class handles this automatically - don't bypass it.

4. **Consider driver failover**: Configure multiple drivers for redundancy:
   ```php
   protected $storage = [
       CacheStorage::class,   // Fast, primary
       FileStorage::class,    // Reliable fallback
   ];
   ```

5. **Clean up resources**: Implement cleanup in `removeFromMemory()` properly.

6. **Type your DataModels**: Use proper types and `#[Desc]` attributes for schema generation.
