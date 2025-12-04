<?php

namespace LarAgent\Context\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Storages\ChatHistoryStorage;

/**
 * Trait providing common filter and query methods for context managers.
 * 
 * Classes using this trait must implement:
 * - newInstance(): static
 * - getContext(): Context
 * - getAllIdentities(): SessionIdentityArray
 */
trait HasContextFilters
{
    /**
     * Array of filter callbacks to apply
     * 
     * @var array<callable>
     */
    protected array $filters = [];

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
     * @param string|Authenticatable $user The user ID or Authenticatable instance to filter by
     * @return static
     */
    public function forUser(string|Authenticatable $user): static
    {
        $userId = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : $user;

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
     * Get identities matching all applied filters.
     *
     * @return SessionIdentityArray
     */
    public function getIdentities(): SessionIdentityArray
    {
        $identities = $this->getAllIdentities();

        // Apply all filters
        foreach ($this->filters as $filter) {
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

    // ==========================================
    // Terminal Query Methods
    // ==========================================

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
     * Check if any identities match the current filters.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
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
     * Collect all matching identities as an array of SessionIdentity objects.
     *
     * @return array<SessionIdentityContract>
     */
    public function all(): array
    {
        return $this->getIdentities()->all();
    }
}
