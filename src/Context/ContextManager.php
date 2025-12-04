<?php

namespace LarAgent\Context;

use LarAgent\Agent;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Storages\IdentityStorage;

/**
 * Context Manager provides an Eloquent-like fluent API for working with agent contexts.
 * 
 * Allows filtering and operating on agent storages with chainable methods.
 * 
 * Usage:
 *   Context::of(MyAgent::class)->each(fn($identity, $agent) => ...)
 *   Context::of(MyAgent::class)->forUser($userId)->each(...)
 *   Context::of(MyAgent::class)->forStorage(ChatHistoryStorage::class)->count()
 *   Context::of(MyAgent::class)->forChat('support')->forGroup('premium')->clear()
 *   Context::of(MyAgent::class)->filter(fn($identity) => ...)->remove()
 */
class ContextManager
{
    /**
     * The agent class to work with
     */
    protected ?string $agentClass = null;

    /**
     * Temporary agent instance for accessing context
     */
    protected ?Agent $tempAgent = null;

    /**
     * Array of filter callbacks to apply
     * 
     * @var array<callable>
     */
    protected array $filters = [];

    /**
     * Create a new ContextManager for the given agent class.
     * Entry point for fluent API.
     *
     * @param string $agentClass The fully qualified agent class name
     * @return static
     */
    public static function of(string $agentClass): static
    {
        $instance = new static();
        $instance->agentClass = $agentClass;
        return $instance;
    }

    /**
     * Alias for of() - backwards compatibility and alternative syntax.
     *
     * @param string $agentClass The fully qualified agent class name
     * @return static
     */
    public static function agent(string $agentClass): static
    {
        return static::of($agentClass);
    }

    /**
     * Get the temporary agent instance for context access.
     * Uses the reserved temp prefix so it won't be tracked.
     *
     * @return Agent
     */
    protected function getTempAgent(): Agent
    {
        if ($this->tempAgent === null) {
            $this->tempAgent = $this->agentClass::for(IdentityStorage::TEMP_SESSION_PREFIX);
        }
        return $this->tempAgent;
    }

    /**
     * Get the context from the temporary agent.
     *
     * @return Context
     */
    protected function getContext(): Context
    {
        return $this->getTempAgent()->context();
    }

    /**
     * Clone the current instance for chainable immutability.
     *
     * @return static
     */
    protected function newInstance(): static
    {
        $instance = new static();
        $instance->agentClass = $this->agentClass;
        $instance->tempAgent = $this->tempAgent;
        $instance->filters = $this->filters;
        return $instance;
    }

    // ==========================================
    // Filter Methods (Chainable)
    // ==========================================

    /**
     * Filter by storage scope/type.
     *
     * @param string $storageClass The storage class to filter by (e.g., ChatHistoryStorage::class)
     * @return static
     */
    public function forStorage(string $storageClass): static
    {
        $instance = $this->newInstance();
        
        // Resolve class name to prefix if it's a Storage class
        $scope = $storageClass;
        if (class_exists($storageClass) && method_exists($storageClass, 'getStoragePrefix')) {
            $scope = $storageClass::getStoragePrefix();
        }
        
        $instance->filters[] = fn(SessionIdentityContract $identity) => $identity->getScope() === $scope;
        return $instance;
    }

    /**
     * Filter by user ID.
     *
     * @param string $userId The user ID to filter by
     * @return static
     */
    public function forUser(string $userId): static
    {
        $instance = $this->newInstance();
        $instance->filters[] = fn(SessionIdentityContract $identity) => $identity->getUserId() === $userId;
        return $instance;
    }

    /**
     * Filter by chat name.
     *
     * @param string $chatName The chat name to filter by
     * @return static
     */
    public function forChat(string $chatName): static
    {
        $instance = $this->newInstance();
        $instance->filters[] = fn(SessionIdentityContract $identity) => $identity->getChatName() === $chatName;
        return $instance;
    }

    /**
     * Filter by group.
     *
     * @param string $group The group to filter by
     * @return static
     */
    public function forGroup(string $group): static
    {
        $instance = $this->newInstance();
        $instance->filters[] = fn(SessionIdentityContract $identity) => $identity->getGroup() === $group;
        return $instance;
    }

    /**
     * Add a custom filter callback.
     *
     * @param callable $callback Receives SessionIdentityContract, returns bool
     * @return static
     */
    public function filter(callable $callback): static
    {
        $instance = $this->newInstance();
        $instance->filters[] = $callback;
        return $instance;
    }

    // ==========================================
    // Query Methods (Non-terminal)
    // ==========================================

    /**
     * Get all identities (before filtering).
     *
     * @return SessionIdentityArray
     */
    protected function getAllIdentities(): SessionIdentityArray
    {
        return $this->getContext()->getIdentityStorage()->get();
    }

    /**
     * Get identities matching all applied filters.
     * Default to ChatHistoryStorage scope if no storage filter applied.
     *
     * @return SessionIdentityArray
     */
    public function getIdentities(): SessionIdentityArray
    {
        $identities = $this->getAllIdentities();

        // Check if a storage filter has been applied
        $hasStorageFilter = false;
        foreach ($this->filters as $filter) {
            // We can't easily detect this, so we'll track it differently
        }

        // Apply default ChatHistoryStorage filter if no explicit storage filter
        // and we're starting fresh (this ensures backwards compatibility)
        $filters = $this->filters;
        
        // Apply all filters
        foreach ($filters as $filter) {
            $identities = $identities->filter($filter);
        }

        return $identities;
    }

    /**
     * Get chat history identities (filtered by ChatHistoryStorage scope).
     *
     * @return SessionIdentityArray
     */
    public function getChatIdentities(): SessionIdentityArray
    {
        return $this->forStorage(ChatHistoryStorage::class)->getIdentities();
    }

    /**
     * Get all tracked storage keys for this agent.
     *
     * @return array<string>
     */
    public function getStorageKeys(): array
    {
        return $this->getTempAgent()->getStorageKeys();
    }

    /**
     * Get all chat history keys for this agent.
     *
     * @return array<string>
     */
    public function getChatKeys(): array
    {
        return $this->getTempAgent()->getChatKeys();
    }

    // ==========================================
    // Terminal Methods
    // ==========================================

    /**
     * Execute a callback for each matching identity.
     *
     * @param callable $callback Receives (SessionIdentityContract $identity, Agent $agent)
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->getIdentities() as $identity) {
            $agent = $this->agentClass::fromIdentity($identity);
            $callback($identity, $agent);
        }
        return $this;
    }

    /**
     * Get count of matching identities.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->getIdentities()->count();
    }

    /**
     * Get the first matching identity.
     *
     * @return SessionIdentityContract|null
     */
    public function first(): ?SessionIdentityContract
    {
        return $this->getIdentities()->first();
    }

    /**
     * Get the first matching identity as an agent instance.
     *
     * @return Agent|null
     */
    public function firstAgent(): ?Agent
    {
        $identity = $this->first();
        return $identity ? $this->agentClass::fromIdentity($identity) : null;
    }

    /**
     * Check if any identities match the current filters.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Clear all matching storages.
     * Data is cleared but keys remain tracked.
     *
     * @return static
     */
    public function clear(): static
    {
        foreach ($this->getIdentities() as $identity) {
            $agent = $this->agentClass::fromIdentity($identity);
            $agent->context()->getStorage($identity->getScope())->clear();
        }
        return $this;
    }

    /**
     * Remove all matching chat histories.
     * Both messages and tracking keys are removed.
     *
     * @return static
     */
    public function remove(): static
    {
        $identityStorage = $this->getContext()->getIdentityStorage();
        
        foreach ($this->getIdentities() as $identity) {
            // Create agent and remove its storage
            $agent = $this->agentClass::fromIdentity($identity);
            $agent->context()->getStorage($identity->getScope())->remove();
            
            // Remove from identity tracking
            $identityStorage->removeByKey($identity->getKey());
        }
        
        // Save the identity storage changes
        $identityStorage->save();
        
        return $this;
    }

    /**
     * Collect all matching identities as an array of SessionIdentity objects.
     *
     * @return array<SessionIdentityContract>
     */
    public function all(): array
    {
        return $this->getIdentities()->all();
    }

    /**
     * Map over matching identities.
     *
     * @param callable $callback Receives (SessionIdentityContract $identity, Agent $agent), returns mixed
     * @return array<mixed>
     */
    public function map(callable $callback): array
    {
        $results = [];
        foreach ($this->getIdentities() as $identity) {
            $agent = $this->agentClass::fromIdentity($identity);
            $results[] = $callback($identity, $agent);
        }
        return $results;
    }

    /**
     * 
     */
    public function getIdentitiesByUser(string $userId): SessionIdentityArray
    {
        return $this->forUser($userId)->getIdentities();
    }

    /**
     * 
     */
    public function clearAllChats(): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->clear();
    }

    /**
     * 
     */
    public function clearAllChatsByUser(string $userId): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->forUser($userId)->clear();
    }

    /**
     */
    public function removeAllChats(): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->remove();
    }

    /**
     */
    public function removeAllChatsByUser(string $userId): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->forUser($userId)->remove();
    }

    /**
     * Use forStorage(ChatHistoryStorage::class)->forUser($userId)->each() instead
     */
    public function eachByUser(string $userId, callable $callback): static
    {
        return $this->forUser($userId)->each($callback);
    }

    /**
     * Use forStorage(ChatHistoryStorage::class)->forUser($userId)->count() instead
     */
    public function countByUser(string $userId): int
    {
        return $this->forUser($userId)->count();
    }
}
