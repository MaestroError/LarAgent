# Storage Abstraction Refactoring Plan

This document outlines the plan to abstract the storage layer used for chat histories and other agent-related data, ensuring backward compatibility while introducing a more flexible, driver-based architecture.

## 1. Core Concepts

-   **Storage Manager**: A central class (`StorageManager`) bound to the `Agent`. It will handle different storage "purposes" (e.g., `chat_history`, `memory`).
-   **Storage Driver**: Concrete implementations (`StorageDriver`) for various backends (JSON, Cache, Database) that conform to a `StorageDriverContract`.
-   **Primary/Secondary Storage**: The `StorageManager` will support a primary driver and multiple secondary drivers. All writes are deferred until the end of the request (in `Agent::__destruct`) to improve performance. The primary storage is used for the main persistence, while secondary storage can be used for backups or caching. Reads will first attempt the primary driver and then fall back to secondary drivers if the primary fails.

## 2. New Interfaces and Classes

### 2.1. Storage Driver Contract

A new interface will define the contract for all storage drivers.

`src/Core/Contracts/StorageDriver.php`:

```php
<?php

namespace LarAgent\Core\Contracts;

interface StorageDriver
{
    /**
     * Retrieve data from storage.
     *
     * @param string $agentName
     * @param string $sessionId
     * @return array|null
     */
    public function get(string $agentName, string $sessionId): ?array;

    /**
     * Persist data to storage.
     *
     * @param string $agentName
     * @param string $sessionId
     * @param array $data
     * @return void
     */
    public function set(string $agentName, string $sessionId, array $data): void;

    /**
     * Delete data from storage.
     *
     * @param string $agentName
     * @param string $sessionId
     * @return void
     */
    public function forget(string $agentName, string $sessionId): void;

    /**
     * Retrieve all session IDs for a given agent.
     *
     * @param string $agentName
     * @return string[]
     */
    public function all(string $agentName): array;
}
```

### 2.2. Storage Manager

This class will manage the lifecycle and logic of primary/secondary storage drivers for different purposes.

`src/Core/Storage/StorageManager.php`:

```php
<?php

namespace LarAgent\Core\Storage;

use LarAgent\Core\Contracts\StorageDriver;

class StorageManager
{
    protected array $config;
    protected array $drivers = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the storage driver for a given purpose.
     *
     * @param string $purpose
     * @return StoragePurposeManager
     */
    public function purpose(string $purpose): StoragePurposeManager
    {
        // Implementation to follow
    }
}
```

### 2.3. Storage Purpose Manager

A helper class, returned by the `StorageManager`, to handle the primary/secondary logic for a specific purpose.

`src/Core/Storage/StoragePurposeManager.php`:

```php
<?php

namespace LarAgent\Core\Storage;

use LarAgent\Core\Contracts\StorageDriver;

class StoragePurposeManager
{
    protected StorageDriver $primary;
    protected array $secondaries = [];
    protected string $agentName;
    protected string $sessionId;

    public function __construct(StorageDriver $primary, array $secondaries, string $agentName, string $sessionId)
    {
        // ...
    }

    public function read(): ?array
    {
        // Read from primary, fallback to secondaries
    }

    public function commit(array $data): void
    {
        // Write to primary and all secondary drivers
    }

    public function forget(): void
    {
        // ...
    }
}
```

### 2.4. New Storage Driver Implementations

We will create new driver classes that implement `StorageDriverContract`.

-   `src/Core/Storage/Drivers/JsonStorageDriver.php`: Uses Laravel's `Storage` facade to handle JSON file operations, replacing the logic in `JsonChatHistory`.
-   `src/Core/Storage/Drivers/CacheStorageDriver.php`: Uses Laravel's `Cache` facade.
-   `src/Core/Storage/Drivers/DatabaseStorageDriver.php`: (For future implementation) Will use the DB facade or Eloquent.

## 3. Changes to Existing Classes

### 3.1. `Agent.php`

-   A new `StorageManager` property will be added: `protected StorageManager $storageManager;`.
-   In the constructor, the `StorageManager` will be instantiated using the application config.
-   The `createChatHistory` method will be updated. Instead of instantiating a history class directly, it will get the `StoragePurposeManager` for the `chat_history` purpose and pass it to the `ChatHistory` constructor.
-   A `__destruct` method will be added to trigger the final save of the chat history.

```php
// In Agent.php

public function __construct($key)
{
    // ...
    $this->storageManager = app(StorageManager::class);
    // ...
}

public function createChatHistory(string $sessionId)
{
    $storage = $this->storageManager->purpose('chat_history')->forSession($this->name(), $sessionId);

    // We can use a single, generic ChatHistory class now
    return new \LarAgent\Core\Abstractions\ChatHistory($storage, [
        'context_window' => $this->contextWindowSize,
        'store_meta' => $this->storeMeta,
    ]);
}

public function __destruct()
{
    $this->storageManager->commit($this->chatHistory);
    $this->onTerminate();
}
```

### 3.2. `Core/Abstractions/ChatHistory.php`

-   This abstract class will be modified to accept a `StoragePurposeManager` instance in its constructor.
-   The `readFromMemory` method will use the `StoragePurposeManager` to read from storage.
-   The `writeToMemory` method will be removed, as writing is now handled centrally by the `Agent`'s `__destruct` method.

```php
// In Core/Abstractions/ChatHistory.php
protected StoragePurposeManager $storage;

public function __construct(StoragePurposeManager $storage, array $options = [])
{
    $this->storage = $storage;
    // ...
}

public function readFromMemory(): void
{
    $data = $this->storage->read();
    $this->setMessages($this->buildMessages($data['messages'] ?? []));
}
```

### 3.3. Existing `History` Classes

-   Classes like `JsonChatHistory`, `CacheChatHistory`, etc., will be either removed or refactored to be lightweight wrappers if needed. The primary goal is to consolidate the storage logic into the new `StorageDriver` classes, making the `ChatHistory` implementations simpler and focused only on managing the message collection in memory.

### 3.4. `LarAgentServiceProvider.php`

-   The service provider will be updated to register the `StorageManager` as a singleton, building it from the new configuration file.

```php
// In LarAgentServiceProvider.php register() method

$this->app->singleton(StorageManager::class, function ($app) {
    $config = $app['config']->get('laragent.storage');
    return new StorageManager($config);
});
```

## 4. Configuration

A new configuration file will be introduced to define the storage drivers and purposes.

`config/laragent.php`:

```php
<?php

return [
    // ... existing config

    'storage' => [
        'drivers' => [
            'json' => [
                'driver' => \LarAgent\Core\Storage\Drivers\JsonStorageDriver::class,
                'disk' => 'local',
                'path' => 'laragent/history',
            ],
            'cache' => [
                'driver' => \LarAgent\Core\Storage\Drivers\CacheStorageDriver::class,
                'store' => 'redis', // or 'file', etc.
                'prefix' => 'laragent_history',
            ],
        ],

        'purposes' => [
            'chat_history' => [
                'primary' => 'json',
                'secondary' => ['cache'],
            ],
            'memory' => [
                'primary' => 'cache',
                'secondary' => [],
            ],
        ],
    ],
];
```

This plan ensures that the external API remains unchanged while introducing a powerful and flexible storage abstraction layer, as requested.
