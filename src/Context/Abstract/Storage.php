<?php

namespace LarAgent\Context\Abstract;

use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Contracts\Storage as StorageContract;
use LarAgent\Context\StorageManager;
use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;

abstract class Storage implements StorageContract
{
    /**
     * The storage manager for items
     */
    protected StorageManager $storageManager;

    /**
     * The identity for this storage instance
     */
    protected SessionIdentityContract $identity;

    /**
     * Cached items
     */
    protected DataModelArray $items;

    /**
     * Flag indicating if items need to be saved
     */
    protected bool $dirty = false;

    /**
     * Flag indicating if items have been loaded from storage
     */
    protected bool $loaded = false;

    /**
     * Default driver class to use when no drivers config provided
     */
    protected array $defaultDrivers = [];

    /**
     * Create a new Storage instance
     *
     * @param  SessionIdentityContract  $identity  The identity for this storage
     * @param  array|string|null  $driversConfig  Configuration for storage drivers
     */
    public function __construct(
        SessionIdentityContract $identity,
        array|string|null $driversConfig = null
    ) {
        // Apply storage-specific scope to the identity for isolation
        $this->identity = $identity->withScope($this->getStoragePrefix());

        // Use default driver if no config provided
        if ($driversConfig === null) {
            $driversConfig = $this->defaultDrivers;
        } elseif (! is_array($driversConfig)) {
            $driversConfig = [$driversConfig];
        }

        $this->storageManager = new StorageManager($driversConfig);

        // Initialize empty DataModelArray
        $this->resetItems();
    }

    /**
     * Get the DataModelArray class name for items
     *
     * @return string The fully qualified class name
     */
    abstract protected function getDataModelClass(): string;

    /**
     * Get the storage prefix/scope for isolation.
     * Different storage types should return unique prefixes to avoid key collisions.
     *
     * @return string The storage prefix (e.g., 'chat_history', 'state', 'memory')
     */
    abstract public static function getStoragePrefix(): string;

    /**
     * Reset items to an empty DataModelArray
     */
    protected function resetItems(): void
    {
        $class = $this->getDataModelClass();
        $this->items = new $class;
    }

    /**
     * Get all items
     */
    public function get(): DataModelArrayContract
    {
        $this->ensureLoaded();

        return $this->items;
    }

    /**
     * Get the identity for this storage
     */
    public function getIdentity(): SessionIdentityContract
    {
        return $this->identity;
    }

    /**
     * Set (replace) all items
     */
    public function set(mixed $items): void
    {
        if (is_array($items)) {
            $class = $this->getDataModelClass();
            $this->items = new $class($items);
        } elseif ($items instanceof DataModelArray) {
            $this->items = $items;
        } else {
            throw new \InvalidArgumentException('Items must be an array or DataModelArray.');
        }
        $this->dirty = true;
        $this->loaded = true;
    }

    /**
     * Add an item to storage
     */
    public function add(DataModelContract $item): void
    {
        $this->ensureLoaded();
        $this->items->add($item);
        $this->dirty = true;
    }

    /**
     * Remove an item from storage
     */
    public function removeItem(mixed $itemOrKey, mixed $value = null): void
    {
        $this->ensureLoaded();
        $this->items->remove($itemOrKey, $value);
        $this->dirty = true;
    }

    /**
     * Get the last item
     */
    public function getLast(): ?DataModelContract
    {
        $this->ensureLoaded();

        return $this->items->last();
    }

    /**
     * Clear all items
     */
    public function clear(): void
    {
        // We can't just set to empty array, we need to clear the DataModelArray
        // But since we might not have loaded it yet, we should just reset it.
        $this->resetItems();
        $this->dirty = true;
        $this->loaded = true;
    }

    /**
     * Get the count of items
     */
    public function count(): int
    {
        $this->ensureLoaded();

        return count($this->items);
    }

    /**
     * Save items to storage (only if changed)
     */
    public function save(): void
    {
        if ($this->dirty) {
            $this->writeItems();
            $this->dirty = false;
        }
    }

    /**
     * Read items from storage (explicit read/refresh)
     */
    public function read(): void
    {
        $this->load();
    }

    /**
     * Write items to storage
     */
    protected function writeItems(): void
    {
        $this->storageManager->save($this->identity, $this->items->toArray());
    }

    /**
     * Check if items need to be saved
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Check if items have been loaded from storage
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Ensure items are loaded from storage (lazy loading)
     */
    protected function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->load();
        }
    }

    /**
     * Load items from storage
     */
    protected function load(): void
    {
        $modelClass = $this->getDataModelClass();
        try {
            $data = $this->storageManager->read($this->identity);
            if ($data === null) {
                $this->resetItems();
            } else {
                $this->items = $modelClass::fromArray($data);
            }
        } catch (\Throwable $e) {
            $this->resetItems();
        }
        $this->loaded = true;
    }

    /**
     * Remove storage completely from all drivers
     */
    public function remove(): void
    {
        $this->storageManager->remove($this->identity);
        $this->resetItems();
        $this->dirty = false;
        $this->loaded = true;
    }
}
