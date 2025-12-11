<?php

namespace LarAgent\Usage;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Core\Traits\SafeEventDispatch;
use LarAgent\Usage\DataModels\UsageArray;
use LarAgent\Usage\DataModels\UsageRecord;

/**
 * Storage for tracking usage statistics per identity.
 *
 * Similar to ChatHistoryStorage, but stores UsageRecord objects.
 */
class UsageStorage extends Storage
{
    use SafeEventDispatch;

    /**
     * Model name used by the agent.
     */
    protected string $modelName = '';

    /**
     * Provider name (label) used by the agent.
     */
    protected string $providerName = '';

    /**
     * Create a new UsageStorage instance.
     *
     * @param  SessionIdentityContract  $identity  The identity for this storage
     * @param  array|string|null  $driversConfig  Configuration for storage drivers
     * @param  string  $modelName  Name of the AI model
     * @param  string  $providerName  Name of the provider
     */
    public function __construct(
        SessionIdentityContract $identity,
        array|string|null $driversConfig = null,
        string $modelName = '',
        string $providerName = ''
    ) {
        parent::__construct($identity, $driversConfig);
        $this->modelName = $modelName;
        $this->providerName = $providerName;
    }

    /**
     * Get the DataModelArray class name for usage records.
     */
    protected function getDataModelClass(): string
    {
        return UsageArray::class;
    }

    /**
     * Get the storage prefix/scope for isolation.
     */
    public static function getStoragePrefix(): string
    {
        return 'usage';
    }

    /**
     * Add a usage record from a Usage DataModel.
     */
    public function addUsage(\LarAgent\Usage\DataModels\Usage $usage): void
    {
        $record = UsageRecord::fromUsage(
            $usage,
            $this->identity,
            $this->modelName,
            $this->providerName
        );

        $this->add($record);
    }

    /**
     * Add a usage record directly.
     */
    public function addRecord(UsageRecord $record): void
    {
        $this->add($record);
    }

    /**
     * Get all usage records.
     */
    public function getUsageRecords(): UsageArray
    {
        /** @var UsageArray $records */
        $records = $this->get();

        return $records;
    }

    /**
     * Get usage records filtered by criteria.
     *
     * @param  array  $filters  Associative array of filters
     *                          Supported: agent_name, user_id, group, model_name, provider_name, date_from, date_to, date
     */
    public function getFilteredUsage(array $filters = []): UsageArray
    {
        $records = $this->getUsageRecords();

        if (isset($filters['agent_name'])) {
            $records = $records->filterByAgent($filters['agent_name']);
        }

        if (array_key_exists('user_id', $filters)) {
            $records = $records->filterByUser($filters['user_id']);
        }

        if (array_key_exists('group', $filters)) {
            $records = $records->filterByGroup($filters['group']);
        }

        if (isset($filters['model_name'])) {
            $records = $records->filterByModel($filters['model_name']);
        }

        if (isset($filters['provider_name'])) {
            $records = $records->filterByProvider($filters['provider_name']);
        }

        if (isset($filters['date'])) {
            $records = $records->filterByDate($filters['date']);
        } elseif (isset($filters['date_from']) || isset($filters['date_to'])) {
            $records = $records->filterByDateRange(
                $filters['date_from'] ?? '1970-01-01',
                $filters['date_to'] ?? null
            );
        }

        return $records;
    }

    /**
     * Get aggregated usage statistics.
     */
    public function aggregate(array $filters = []): array
    {
        return $this->getFilteredUsage($filters)->aggregate();
    }

    /**
     * Get usage grouped by a specific field.
     *
     * @param  string  $field  Field to group by (agent_name, user_id, model_name, provider_name)
     * @param  array  $filters  Optional filters to apply before grouping
     */
    public function groupBy(string $field, array $filters = []): array
    {
        return $this->getFilteredUsage($filters)->groupBy($field);
    }

    /**
     * Get the last usage record.
     */
    public function getLastUsage(): ?UsageRecord
    {
        /** @var UsageRecord|null $record */
        $record = $this->getLast();

        return $record;
    }

    /**
     * Convert usage records to array format.
     */
    public function toArray(): array
    {
        return $this->getUsageRecords()->toArray();
    }

    /**
     * Get the identifier for this usage storage.
     */
    public function getIdentifier(): string
    {
        return $this->identity->getKey();
    }

    /**
     * Set the model name.
     */
    public function setModelName(string $modelName): static
    {
        $this->modelName = $modelName;

        return $this;
    }

    /**
     * Set the provider name.
     */
    public function setProviderName(string $providerName): static
    {
        $this->providerName = $providerName;

        return $this;
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->modelName;
    }

    /**
     * Get the provider name.
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * Force read from storage drivers (bypasses lazy loading).
     */
    public function readFromMemory(): void
    {
        $this->load();
    }

    /**
     * Force write to storage drivers (bypasses dirty check).
     */
    public function writeToMemory(): void
    {
        $this->writeItems();
        $this->dirty = false;
    }
}
