<?php

namespace LarAgent\Context\Drivers;

use Illuminate\Support\Facades\Cache;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;

class CacheStorage extends StorageDriver
{
    /**
     * The cache store to use
     */
    protected ?string $store;

    /**
     * Create a new CacheDriver instance
     *
     * @param  string|null  $store  The cache store to use (null for default)
     */
    public function __construct(?string $store = null)
    {
        $this->store = $store;
    }

    /**
     * Read data from cache
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $key = $identity->getKey();

        $data = $this->store
            ? Cache::store($this->store)->get($key)
            : Cache::get($key);

        return $data;
    }

    /**
     * Write data to cache
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        $key = $identity->getKey();

        if ($this->store) {
            Cache::store($this->store)->put($key, $data);
        } else {
            Cache::put($key, $data);
        }

        return true;
    }

    /**
     * Remove data from cache
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        $key = $identity->getKey();

        if ($this->store) {
            Cache::store($this->store)->forget($key);
        } else {
            Cache::forget($key);
        }

        return true;
    }
}
