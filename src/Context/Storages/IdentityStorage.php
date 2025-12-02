<?php

namespace LarAgent\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\SessionIdentity;
use LarAgent\Events\IdentityStorage\IdentityStorageLoaded;
use LarAgent\Events\IdentityStorage\IdentityStorageSaving;
use LarAgent\Events\IdentityStorage\IdentityStorageSaved;
use LarAgent\Events\IdentityStorage\IdentityAdding;
use LarAgent\Events\IdentityStorage\IdentityAdded;

/**
 * A specialized storage that tracks all storage identities registered within a context.
 * 
 * This enables:
 * - Listing all storages related to an agent
 * - Cleanup operations
 * - Key discovery
 * 
 * The IdentityStorage uses its own identity derived from the agent name + "context" scope,
 * separate from the session identity used by other storages.
 */
class IdentityStorage extends Storage
{
    /**
     * Get the DataModelArray class name for identities
     * 
     * @return string The fully qualified class name
     */
    protected function getDataModelClass(): string
    {
        return SessionIdentityArray::class;
    }

    /**
     * Get the storage prefix/scope for isolation.
     * 
     * @return string The storage prefix
     */
    public static function getStoragePrefix(): string
    {
        return 'context';
    }

    /**
     * Add a storage identity to track.
     *
     * @param SessionIdentityContract $identity The storage identity to track
     * @return void
     */
    public function addIdentity(SessionIdentityContract $identity): void
    {
        // Dispatch IdentityAdding event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new IdentityAdding($this, $identity));
        }

        $this->ensureLoaded();
        
        /** @var SessionIdentityArray $items */
        if (!$this->items->hasKey($identity->getKey())) {
            $this->items->add($identity);
            $this->dirty = true;
        }

        // Dispatch IdentityAdded event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new IdentityAdded($this, $identity));
        }
    }

    /**
     * Remove a storage identity from tracking by its key.
     *
     * @param string $key The storage key to remove
     * @return void
     */
    public function removeByKey(string $key): void
    {
        $this->ensureLoaded();
        
        /** @var SessionIdentityArray $items */
        if ($this->items->hasKey($key)) {
            $this->items->removeByKey($key);
            $this->dirty = true;
        }
    }

    /**
     * Check if a storage key is being tracked.
     *
     * @param string $key The storage key to check
     * @return bool
     */
    public function hasKey(string $key): bool
    {
        return $this->get()->hasKey($key);
    }

    /**
     * Get a tracked identity by its key.
     *
     * @param string $key The storage key
     * @return SessionIdentityContract|null
     */
    public function getByKey(string $key): ?SessionIdentityContract
    {
        return $this->get()->getByKey($key);
    }

    /**
     * Get all tracked storage keys.
     *
     * @return array<string>
     */
    public function getKeys(): array
    {
        return $this->get()->getKeys();
    }

    /**
     * Get all tracked identities.
     *
     * @return SessionIdentityArray
     */
    public function getIdentities(): SessionIdentityArray
    {
        return $this->get();
    }

    /**
     * Save identities to storage (only if changed).
     * Dispatches events before and after saving.
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        // Dispatch IdentityStorageSaving event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new IdentityStorageSaving($this, $this->getIdentities()));
        }

        $this->writeItems();
        $this->dirty = false;

        // Dispatch IdentityStorageSaved event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new IdentityStorageSaved($this));
        }
    }

    /**
     * Load identities from storage.
     * Dispatches event after loading.
     *
     * @return void
     */
    protected function load(): void
    {
        parent::load();

        // Dispatch IdentityStorageLoaded event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new IdentityStorageLoaded($this, $this->items));
        }
    }
}
