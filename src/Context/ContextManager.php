<?php

namespace LarAgent\Context;

use LarAgent\Agent;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Context\Traits\HasContextFilters;

/**
 * Context Manager provides an Eloquent-like fluent API for working with agent contexts.
 *
 * Allows filtering and operating on agent storages with chainable methods.
 *
 * Is created to access contexts outside of agent.
 *
 * Inside agent, use `$this->context()` instead.
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
    use HasContextFilters;

    /**
     * The agent class to work with
     */
    protected ?string $agentClass = null;

    /**
     * Temporary agent instance for accessing context
     */
    protected ?Agent $tempAgent = null;

    /**
     * Create a new ContextManager for the given agent class.
     * Entry point for fluent API.
     *
     * @param  string  $agentClass  The fully qualified agent class name
     */
    public static function of(string $agentClass): static
    {
        $instance = new static;
        $instance->agentClass = $agentClass;

        return $instance;
    }

    /**
     * Alias for of() - backwards compatibility and alternative syntax.
     *
     * @param  string  $agentClass  The fully qualified agent class name
     */
    public static function agent(string $agentClass): static
    {
        return static::of($agentClass);
    }

    /**
     * Create a named context manager for lightweight access.
     * Does not require agent class initialization.
     *
     * @param  string  $agentName  The agent name (without namespace)
     */
    public static function named(string $agentName): NamedContextManager
    {
        return NamedContextManager::named($agentName);
    }

    /**
     * Get the temporary agent instance for context access.
     * Uses the reserved temp prefix so it won't be tracked.
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
     */
    protected function getContext(): Context
    {
        return $this->getTempAgent()->context();
    }

    /**
     * Clone the current instance for chainable immutability.
     */
    protected function newInstance(): static
    {
        $instance = new static;
        $instance->agentClass = $this->agentClass;
        $instance->tempAgent = $this->tempAgent;
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
     * @param  callable  $callback  Receives (SessionIdentityContract $identity, Agent $agent)
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
     * Get the first matching identity as an agent instance.
     */
    public function firstAgent(): ?Agent
    {
        $identity = $this->first();

        return $identity ? $this->agentClass::fromIdentity($identity) : null;
    }

    /**
     * Clear all matching storages.
     * Data is cleared but keys remain tracked.
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
     * Map over matching identities.
     *
     * @param  callable  $callback  Receives (SessionIdentityContract $identity, Agent $agent), returns mixed
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

    public function getIdentitiesByUser(string $userId): SessionIdentityArray
    {
        return $this->forUser($userId)->getIdentities();
    }

    public function clearAllChats(): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->clear();
    }

    public function clearAllChatsByUser(string $userId): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->forUser($userId)->clear();
    }

    public function removeAllChats(): static
    {
        return $this->forStorage(ChatHistoryStorage::class)->remove();
    }

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
