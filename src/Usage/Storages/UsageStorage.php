<?php

namespace LarAgent\Usage\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Core\Traits\SafeEventDispatch;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Usage\DataModels\UsageArray;
use LarAgent\Usage\Events\UsageAdded;
use LarAgent\Usage\Events\UsageAdding;
use LarAgent\Usage\Events\UsageStorageLoaded;
use LarAgent\Usage\Events\UsageStorageSaved;
use LarAgent\Usage\Events\UsageStorageSaving;

class UsageStorage extends Storage
{
    use SafeEventDispatch;

    /**
     * Create a new UsageStorage instance
     *
     * @param  SessionIdentityContract  $identity  The identity for this storage
     * @param  array|string|null  $driversConfig  Configuration for storage drivers
     */
    public function __construct(
        SessionIdentityContract $identity,
        array|string|null $driversConfig = null
    ) {
        parent::__construct($identity, $driversConfig);
    }

    /**
     * Get the DataModelArray class name for usage entries
     *
     * @return string The fully qualified class name
     */
    protected function getDataModelClass(): string
    {
        return UsageArray::class;
    }

    /**
     * Get the storage prefix/scope for isolation.
     *
     * @return string The storage prefix
     */
    public static function getStoragePrefix(): string
    {
        return 'usage';
    }

    /**
     * Add a usage entry to the storage
     */
    public function addUsage(Usage $usage): void
    {
        // Dispatch UsageAdding event
        $this->dispatchEvent(new UsageAdding($this, $usage));

        $this->add($usage);

        // Dispatch UsageAdded event
        $this->dispatchEvent(new UsageAdded($this, $usage));
    }

    /**
     * Get all usage entries from the storage
     */
    public function getUsages(): UsageArray
    {
        return $this->get();
    }

    /**
     * Get the last usage entry
     */
    public function getLastUsage(): ?Usage
    {
        return $this->getLast();
    }

    /**
     * Convert usage entries to array format
     */
    public function toArray(): array
    {
        return $this->getUsages()->toArray();
    }

    /**
     * Get the identifier for this usage storage
     */
    public function getIdentifier(): string
    {
        return $this->identity->getKey();
    }

    /**
     * Force read from storage drivers (bypasses lazy loading)
     */
    public function readFromMemory(): void
    {
        $this->load();
    }

    /**
     * Force write to storage drivers (bypasses dirty check)
     */
    public function writeToMemory(): void
    {
        $this->writeItems();
        $this->dirty = false;
    }

    /**
     * Save usage entries to storage (only if changed)
     * Dispatches events before and after saving
     */
    public function save(): void
    {
        if (! $this->dirty) {
            return;
        }

        // Dispatch UsageStorageSaving event
        $this->dispatchEvent(new UsageStorageSaving($this, $this->getUsages()));

        $this->writeItems();
        $this->dirty = false;

        // Dispatch UsageStorageSaved event
        $this->dispatchEvent(new UsageStorageSaved($this));
    }

    /**
     * Load usage entries from storage
     * Dispatches event after loading
     */
    protected function load(): void
    {
        parent::load();

        // Dispatch UsageStorageLoaded event
        $this->dispatchEvent(new UsageStorageLoaded($this, $this->items));
    }

    /**
     * Filter usage entries by various criteria
     */
    public function filter(callable $callback): UsageArray
    {
        return $this->getUsages()->filter($callback);
    }

    /**
     * Filter usage by user ID
     */
    public function filterByUserId(string $userId): UsageArray
    {
        return $this->filter(function (Usage $usage) use ($userId) {
            return $usage->userId === $userId;
        });
    }

    /**
     * Filter usage by model name
     */
    public function filterByModel(string $model): UsageArray
    {
        return $this->filter(function (Usage $usage) use ($model) {
            return $usage->model === $model;
        });
    }

    /**
     * Filter usage by provider name
     */
    public function filterByProvider(string $provider): UsageArray
    {
        return $this->filter(function (Usage $usage) use ($provider) {
            return $usage->provider === $provider;
        });
    }

    /**
     * Filter usage by agent name
     */
    public function filterByAgent(string $agent): UsageArray
    {
        return $this->filter(function (Usage $usage) use ($agent) {
            return $usage->agent === $agent;
        });
    }

    /**
     * Filter usage by date range
     */
    public function filterByDateRange(?string $startDate = null, ?string $endDate = null): UsageArray
    {
        return $this->filter(function (Usage $usage) use ($startDate, $endDate) {
            if ($usage->createdAt === null) {
                return false;
            }

            $createdTime = strtotime($usage->createdAt);

            if ($startDate !== null && $createdTime < strtotime($startDate)) {
                return false;
            }

            if ($endDate !== null && $createdTime > strtotime($endDate)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get total token usage aggregated
     */
    public function getTotalUsage(): array
    {
        $total = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        foreach ($this->getUsages() as $usage) {
            $total['prompt_tokens'] += $usage->promptTokens;
            $total['completion_tokens'] += $usage->completionTokens;
            $total['total_tokens'] += $usage->totalTokens;
        }

        return $total;
    }
}
