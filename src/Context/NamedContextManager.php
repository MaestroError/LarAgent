<?php

namespace LarAgent\Context;

use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Traits\HasContextFilters;

/**
 * Named Context Manager provides context access via agent name string,
 * without requiring full agent class initialization.
 *
 * Useful when:
 * - Working with contexts outside of agent instances
 * - Providing custom driver configurations directly
 * - Administrative tasks that don't need full agent functionality
 *
 * Usage:
 *   Context::named('MyAgent')->each(fn($identity) => ...)
 *   Context::named('MyAgent')->withDrivers([SessionDriver::class])->clearAllChats()
 *   Context::named('MyAgent')->forUser($userId)->count()
 */
class NamedContextManager
{
    use HasContextFilters;

    /**
     * The agent name to work with
     */
    protected string $agentName;

    /**
     * Driver configuration for storages
     */
    protected array $driversConfig = [];

    /**
     * Context instance (lazily created)
     */
    protected ?Context $context = null;

    /**
     * Create a new NamedContextManager for the given agent name.
     * Entry point for fluent API.
     *
     * @param  string  $agentName  The agent name (without namespace)
     */
    public static function named(string $agentName): static
    {
        $instance = new static;
        $instance->agentName = $agentName;

        return $instance;
    }

    /**
     * Set custom driver configuration.
     *
     * @param  array  $driversConfig  Array of driver classes
     */
    public function withDrivers(array $driversConfig): static
    {
        $instance = $this->newInstance();
        $instance->driversConfig = $driversConfig;
        $instance->context = null; // Reset context to use new drivers

        return $instance;
    }

    /**
     * Get the context instance.
     * Creates a lightweight context using just the agent name.
     */
    protected function getContext(): Context
    {
        if ($this->context === null) {
            $identity = new SessionIdentity(
                agentName: $this->agentName
            );

            $drivers = ! empty($this->driversConfig)
                ? $this->driversConfig
                : $this->defaultDrivers();

            $this->context = new Context($identity, $drivers);
            $this->context->getIdentityStorage()->read();
        }

        return $this->context;
    }

    /**
     * Get default drivers configuration.
     * Override this method to customize default drivers.
     */
    protected function defaultDrivers(): array
    {
        // Try to get from config, fallback to InMemoryStorage
        $configValue = function_exists('config') ? config('laragent.history') : null;

        if ($configValue !== null) {
            return is_array($configValue) ? $configValue : [$configValue];
        }

        return [InMemoryStorage::class];
    }

    /**
     * Clone the current instance for chainable immutability.
     */
    protected function newInstance(): static
    {
        $instance = new static;
        $instance->agentName = $this->agentName;
        $instance->driversConfig = $this->driversConfig;
        $instance->context = $this->context;
        $instance->filters = $this->filters;

        return $instance;
    }

    // ==========================================
    // Query Methods (Non-terminal)
    // ==========================================

    /**
     * Get all identities (before filtering).
     */
    protected function getAllIdentities(): SessionIdentityArray
    {
        return $this->getContext()->getIdentityStorage()->get();
    }

    /**
     * Get all tracked storage keys.
     *
     * @return array<string>
     */
    public function getStorageKeys(): array
    {
        return $this->getContext()->getTrackedKeys();
    }

    /**
     * Get all chat history keys.
     *
     * @return array<string>
     */
    public function getChatKeys(): array
    {
        return $this->getContext()->getTrackedKeysByPrefix(ChatHistoryStorage::getStoragePrefix());
    }

    /**
     * Check if no identities match current filters.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Get the last identity matching filters, or null.
     */
    public function last(): ?SessionIdentityContract
    {
        return $this->getIdentities()->last();
    }

    // ==========================================
    // Iteration Methods
    // ==========================================

    /**
     * Iterate over matching identities with a callback.
     * The callback receives the identity.
     *
     * @param  callable  $callback  Function(SessionIdentityContract $identity)
     */
    public function each(callable $callback): static
    {
        foreach ($this->getIdentities() as $identity) {
            $callback($identity);
        }

        return $this;
    }

    /**
     * Map over matching identities and return results.
     *
     * @param  callable  $callback  Function(SessionIdentityContract $identity): mixed
     */
    public function map(callable $callback): array
    {
        $results = [];
        foreach ($this->getIdentities() as $identity) {
            $results[] = $callback($identity);
        }

        return $results;
    }

    // ==========================================
    // Terminal Action Methods
    // ==========================================

    /**
     * Clear chat history for all matching identities.
     * This clears the data but keeps the storage entries.
     *
     * @return int Number of cleared chats
     */
    public function clearAllChats(): int
    {
        $identities = $this->forStorage(ChatHistoryStorage::class)->getIdentities();
        $count = 0;

        foreach ($identities as $identity) {
            $storage = new ChatHistoryStorage($identity, $this->getDriversConfig());
            $storage->clear();
            $storage->save();
            $count++;
        }

        return $count;
    }

    /**
     * Remove chat history storage entries for all matching identities.
     * This deletes the storage entries entirely.
     *
     * @return int Number of removed chats
     */
    public function removeAllChats(): int
    {
        $identities = $this->forStorage(ChatHistoryStorage::class)->getIdentities();
        $count = 0;

        foreach ($identities as $identity) {
            $storage = new ChatHistoryStorage($identity, $this->getDriversConfig());
            $storage->remove();
            $this->getContext()->removeIdentityFromTracking($identity->getKey());
            $count++;
        }

        // Save the identity storage to persist the removal
        $this->getContext()->getIdentityStorage()->save();

        return $count;
    }

    /**
     * Clear all registered storages for matching identities.
     *
     * @return int Number of cleared contexts
     */
    public function clearAll(): int
    {
        $identities = $this->getIdentities();
        $count = 0;

        foreach ($identities as $identity) {
            // Get the storage class from identity scope
            $scope = $identity->getScope();
            $storageClass = $this->resolveStorageClass($scope);

            if ($storageClass) {
                $storage = new $storageClass($identity, $this->getDriversConfig());
                $storage->clear();
                $storage->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove all storage entries for matching identities.
     *
     * @return int Number of removed entries
     */
    public function removeAll(): int
    {
        $identities = $this->getIdentities();
        $count = 0;

        foreach ($identities as $identity) {
            // Get the storage class from identity scope
            $scope = $identity->getScope();
            $storageClass = $this->resolveStorageClass($scope);

            if ($storageClass) {
                $storage = new $storageClass($identity, $this->getDriversConfig());
                $storage->remove();
                $this->getContext()->removeIdentityFromTracking($identity->getKey());
                $count++;
            }
        }

        // Save the identity storage to persist the removal
        $this->getContext()->getIdentityStorage()->save();

        return $count;
    }

    // ==========================================
    // Utility Methods
    // ==========================================

    /**
     * Get the agent name.
     */
    public function getAgentName(): string
    {
        return $this->agentName;
    }

    /**
     * Get the drivers configuration.
     */
    public function getDriversConfig(): array
    {
        return ! empty($this->driversConfig)
            ? $this->driversConfig
            : $this->defaultDrivers();
    }

    /**
     * Get the underlying Context instance.
     */
    public function context(): Context
    {
        return $this->getContext();
    }

    /**
     * Resolve storage class from scope/prefix.
     *
     * @param  string  $scope  The storage scope/prefix
     * @return string|null The storage class name or null
     */
    protected function resolveStorageClass(string $scope): ?string
    {
        // Map known prefixes to storage classes
        $storageMap = [
            ChatHistoryStorage::getStoragePrefix() => ChatHistoryStorage::class,
            // Add more storage mappings as needed
        ];

        return $storageMap[$scope] ?? null;
    }
}
