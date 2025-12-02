<?php

namespace LarAgent\Context\DataModels;

use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Core\Abstractions\DataModelArray;

/**
 * Array of SessionIdentity models.
 * Used by IdentityStorage to store a collection of tracked storage identities.
 */
class SessionIdentityArray extends DataModelArray
{
    /**
     * Return the list of allowed DataModel classes.
     *
     * @return array<string>
     */
    public static function allowedModels(): array
    {
        return [SessionIdentity::class];
    }

    /**
     * Check if an identity with the given key exists.
     *
     * @param string $key
     * @return bool
     */
    public function hasKey(string $key): bool
    {
        return $this->hasItem('key', $key);
    }

    /**
     * Get an identity by its key.
     *
     * @param string $key
     * @return SessionIdentityContract|null
     */
    public function getByKey(string $key): ?SessionIdentityContract
    {
        return $this->getItem('key', $key);
    }

    /**
     * Remove an identity by its key.
     *
     * @param string $key
     * @return static
     */
    public function removeByKey(string $key): static
    {
        return $this->removeItem('key', $key);
    }

    /**
     * Get all keys as a simple string array.
     *
     * @return array<string>
     */
    public function getKeys(): array
    {
        return $this->map(fn(SessionIdentity $item) => $item->getKey());
    }
}
