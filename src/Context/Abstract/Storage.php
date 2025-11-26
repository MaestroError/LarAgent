<?php

namespace LarAgent\Context\Abstract;

use LarAgent\Context\Contracts\Storage as StorageContract;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\StorageManager;
use LarAgent\Core\Contracts\DataModel;

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
     * Cached items (array of DataModel)
     * @var DataModel[]
     */
    protected array $items = [];

    /**
     * Flag indicating if items need to be saved
     */
    protected bool $dirty = false;

    /**
     * Flag indicating if items have been loaded from storage
     */
    protected bool $loaded = false;

    /**
     * Create a new Storage instance
     *
     * @param array $driversConfig Configuration for storage drivers
     * @param SessionIdentityContract $identity The identity for this storage
     */
    public function __construct(
        array $driversConfig,
        SessionIdentityContract $identity
    ) {
        $this->identity = $identity;
        $this->storageManager = new StorageManager($driversConfig);
    }

    /**
     * Get the DataModel class name for items
     * 
     * @return string The fully qualified class name
     */
    abstract protected function getDataModelClass(): string;

    /**
     * Get all items
     *
     * @return DataModel[]
     */
    public function get(): array
    {
        $this->ensureLoaded();
        return $this->items;
    }

    /**
     * Get the identity for this storage
     *
     * @return SessionIdentityContract
     */
    public function getIdentity(): SessionIdentityContract
    {
        return $this->identity;
    }

    /**
     * Set (replace) all items
     *
     * @param DataModel[] $items
     * @return void
     */
    public function set(array $items): void
    {
        $this->items = $items;
        $this->dirty = true;
        $this->loaded = true;
    }

    /**
     * Get the last item
     *
     * @return DataModel|null
     */
    public function getLast(): ?DataModel
    {
        $this->ensureLoaded();
        if (empty($this->items)) {
            return null;
        }
        return end($this->items) ?: null;
    }

    /**
     * Clear all items (sets items as empty array)
     *
     * @return void
     */
    public function clear(): void
    {
        $this->items = [];
        $this->dirty = true;
        $this->loaded = true;
    }

    /**
     * Get the count of items
     *
     * @return int
     */
    public function count(): int
    {
        $this->ensureLoaded();
        return count($this->items);
    }

    /**
     * Save items to storage (only if changed)
     *
     * @return void
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
     *
     * @return void
     */
    public function read(): void
    {
        $this->load();
    }

    /**
     * Write items to storage
     *
     * @return void
     */
    protected function writeItems(): void
    {
        $data = array_map(
            fn(DataModel $item) => $item->toArray(),
            $this->items
        );
        $this->storageManager->save($this->identity, $data);
    }

    /**
     * Check if items need to be saved
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Check if items have been loaded from storage
     *
     * @return bool
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Ensure items are loaded from storage (lazy loading)
     *
     * @return void
     */
    protected function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    /**
     * Load items from storage
     *
     * @return void
     */
    protected function load(): void
    {
        try {
            $data = $this->storageManager->read($this->identity);
            $modelClass = $this->getDataModelClass();
            $this->items = array_map(
                fn(array $itemData) => $modelClass::fromArray($itemData),
                $data
            );
        } catch (\Throwable $e) {
            $this->items = [];
        }
        $this->loaded = true;
    }

    /**
     * Remove storage completely from all drivers
     *
     * @return void
     */
    public function remove(): void
    {
        $this->storageManager->remove($this->identity);
        $this->items = [];
        $this->dirty = false;
        $this->loaded = true;
    }
}
