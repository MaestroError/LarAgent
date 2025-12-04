<?php

namespace LarAgent\Context;

use LarAgent\Context\Contracts\Context as ContextContract;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Contracts\Storage as StorageContract;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Core\Traits\SafeEventDispatch;
use LarAgent\Events\Context\ContextCleared;
use LarAgent\Events\Context\ContextClearing;
use LarAgent\Events\Context\ContextCreated;
use LarAgent\Events\Context\ContextRead;
use LarAgent\Events\Context\ContextReading;
use LarAgent\Events\Context\ContextSaved;
use LarAgent\Events\Context\ContextSaving;
use LarAgent\Events\Context\StorageRegistered;

/**
 * Context serves as a central orchestration layer that manages multiple storage instances for an Agent.
 *
 * It acts as:
 * 1. A registration place for different storages
 * 2. A unified API for bulk operations (save, clear, read)
 * 3. A session identity manager
 * 4. A provider of direct access to storage instances
 */
class Context implements ContextContract
{
    use SafeEventDispatch;

    /**
     * Base identity for this context
     */
    protected SessionIdentityContract $identity;

    /**
     * Identity used for the context's own storage (IdentityStorage)
     */
    protected SessionIdentityContract $contextIdentity;

    /**
     * Registered storage instances indexed by prefix
     *
     * @var array<string, StorageContract>
     */
    protected array $storages = [];

    /**
     * Tracks all storage identities
     */
    protected IdentityStorage $identityStorage;

    /**
     * Default driver configuration
     */
    protected array $driversConfig;

    /**
     * Create a new Context instance
     *
     * @param  SessionIdentityContract  $identity  The base identity for this context
     * @param  array  $driversConfig  Configuration for storage drivers
     */
    public function __construct(
        SessionIdentityContract $identity,
        array $driversConfig = []
    ) {
        $this->identity = $identity;
        $this->driversConfig = $driversConfig;

        // Build the context identity for IdentityStorage
        $this->contextIdentity = $this->buildContextIdentity($identity);

        // Initialize identity storage to track all registered storages
        $this->identityStorage = $this->buildIdentityStorage();

        // Dispatch ContextCreated event
        $this->dispatchEvent(new ContextCreated($this));
    }

    /**
     * Build the identity for IdentityStorage.
     * Uses only agent name to ensure all sessions of the same agent share the same identity storage.
     * Override this method to customize context identity generation.
     *
     * @param  SessionIdentityContract  $identity  The base session identity
     */
    protected function buildContextIdentity(SessionIdentityContract $identity): SessionIdentityContract
    {
        return new SessionIdentity(
            agentName: $identity->getAgentName()
        );
    }

    /**
     * Build the IdentityStorage instance.
     * Override this method to customize identity storage creation.
     */
    protected function buildIdentityStorage(): IdentityStorage
    {
        return new IdentityStorage($this->contextIdentity, $this->driversConfig);
    }

    /**
     * Get the context identity used for IdentityStorage.
     */
    public function getContextIdentity(): SessionIdentityContract
    {
        return $this->contextIdentity;
    }

    /**
     * Get the session identity for this context
     */
    public function getIdentity(): SessionIdentityContract
    {
        return $this->identity;
    }

    /**
     * Register a storage instance.
     * Uses storage's getStoragePrefix() as the registration key.
     * Automatically tracks the storage identity in identity storage.
     *
     * @param  StorageContract  $storage  The storage instance to register
     */
    public function register(StorageContract $storage): static
    {
        $prefix = $storage->getStoragePrefix();
        $this->storages[$prefix] = $storage;
        $this->identityStorage->addIdentity($storage->getIdentity());

        // Dispatch StorageRegistered event
        $this->dispatchEvent(new StorageRegistered($this, $prefix, $storage));

        return $this;
    }

    /**
     * Create and register a storage from class name.
     * Registration key is derived from storage's getStoragePrefix().
     *
     * @param  string  $storageClass  The fully qualified storage class name
     * @param  array  $driversConfig  Optional custom driver configuration (uses default if empty)
     * @return StorageContract The created storage instance
     */
    public function make(string $storageClass, array $driversConfig = []): StorageContract
    {
        $config = ! empty($driversConfig) ? $driversConfig : $this->driversConfig;

        $storage = new $storageClass($this->identity, $config);
        $this->register($storage);

        return $storage;
    }

    /**
     * Get a registered storage by prefix or class name.
     * Uses storage's getStoragePrefix() as the registration key.
     *
     * @param  string  $prefixOrClass  The storage prefix (e.g., 'chat_history') or fully qualified class name
     */
    public function getStorage(string $prefixOrClass): ?StorageContract
    {
        $prefix = $this->resolvePrefix($prefixOrClass);

        return $this->storages[$prefix] ?? null;
    }

    /**
     * Check if a storage is registered by prefix or class name.
     *
     * @param  string  $prefixOrClass  The storage prefix or fully qualified class name
     */
    public function has(string $prefixOrClass): bool
    {
        $prefix = $this->resolvePrefix($prefixOrClass);

        return isset($this->storages[$prefix]);
    }

    /**
     * Get all registered storage prefixes/names.
     *
     * @return array<string>
     */
    public function getStorageNames(): array
    {
        return array_keys($this->storages);
    }

    /**
     * Save all dirty storages and the identity storage.
     */
    public function save(): void
    {
        // Dispatch ContextSaving event
        $this->dispatchEvent(new ContextSaving($this));

        foreach ($this->storages as $storage) {
            $storage->save();
        }
        $this->identityStorage->save();

        // Dispatch ContextSaved event
        $this->dispatchEvent(new ContextSaved($this));
    }

    /**
     * Read/refresh all storages from their drivers.
     */
    public function read(): void
    {
        // Dispatch ContextReading event
        $this->dispatchEvent(new ContextReading($this));

        foreach ($this->storages as $storage) {
            $storage->read();
        }

        // Dispatch ContextRead event
        $this->dispatchEvent(new ContextRead($this));
    }

    /**
     * Clear all storages (marks as dirty, sets to empty).
     */
    public function clear(): void
    {
        // Dispatch ContextClearing event
        $this->dispatchEvent(new ContextClearing($this));

        foreach ($this->storages as $storage) {
            $storage->clear();
        }

        // Dispatch ContextCleared event
        $this->dispatchEvent(new ContextCleared($this));
    }

    /**
     * Remove all storages from their drivers and clear identity storage.
     */
    public function remove(): void
    {
        foreach ($this->storages as $storage) {
            $storage->remove();
        }
        $this->identityStorage->clear();
        $this->identityStorage->save();
    }

    /**
     * Get all storage keys tracked by this context.
     *
     * @return array<string>
     */
    public function getTrackedKeys(): array
    {
        return $this->identityStorage->getKeys();
    }

    /**
     * Get storage keys tracked by this context, filtered by prefix.
     *
     * @param  string  $prefix  The storage prefix to filter by (e.g., 'chatHistory')
     * @return array<string>
     */
    public function getTrackedKeysByPrefix(string $prefix): array
    {
        return $this->identityStorage->getKeysByPrefix($prefix);
    }

    /**
     * Get tracked identities filtered by scope.
     *
     * @param  string  $scope  The scope to filter by (e.g., 'chatHistory')
     */
    public function getTrackedIdentitiesByScope(string $scope): \LarAgent\Context\DataModels\SessionIdentityArray
    {
        return $this->identityStorage->getIdentitiesByScope($scope);
    }

    /**
     * Get the identity storage instance.
     */
    public function getIdentityStorage(): IdentityStorage
    {
        return $this->identityStorage;
    }

    public function removeIdentityFromTracking(string $key): void
    {
        $this->identityStorage->removeByKey($key);
    }

    /**
     * Get the drivers configuration.
     */
    public function getDriversConfig(): array
    {
        return $this->driversConfig;
    }

    /**
     * Resolve prefix from string or class name.
     * If the string is a valid Storage class, calls its static getStoragePrefix().
     */
    protected function resolvePrefix(string $prefixOrClass): string
    {
        if (class_exists($prefixOrClass) && is_subclass_of($prefixOrClass, StorageContract::class)) {
            return $prefixOrClass::getStoragePrefix();
        }

        return $prefixOrClass;
    }

    /**
     * Magic getter for direct storage access.
     * Allows: $context->chat_history instead of $context->getStorage('chat_history')
     *
     * @param  string  $prefix  The storage prefix
     */
    public function __get(string $prefix): ?StorageContract
    {
        return $this->getStorage($prefix);
    }
}
