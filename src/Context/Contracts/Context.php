<?php

namespace LarAgent\Context\Contracts;

interface Context
{
    /**
     * Get the session identity for this context
     */
    public function getIdentity(): SessionIdentity;

    /**
     * Get a registered storage by prefix or class name
     * Uses storage's getStoragePrefix() as the registration key
     *
     * @param  string  $prefixOrClass  The storage prefix (e.g., 'chat_history') or fully qualified class name
     */
    public function getStorage(string $prefixOrClass): ?Storage;

    /**
     * Register a storage instance
     * Uses storage's getStoragePrefix() method as the registration key
     *
     * @param  Storage  $storage  The storage instance to register
     */
    public function register(Storage $storage): static;

    /**
     * Check if a storage is registered by prefix or class name
     * Accepts either a prefix string or a Storage class name
     *
     * @param  string  $prefixOrClass  The storage prefix or fully qualified class name
     */
    public function has(string $prefixOrClass): bool;

    /**
     * Get all registered storage prefixes/names
     *
     * @return array<string>
     */
    public function getStorageNames(): array;

    /**
     * Save all dirty storages
     */
    public function save(): void;

    /**
     * Read/refresh all storages from their drivers
     */
    public function read(): void;

    /**
     * Clear all storages (marks as dirty, sets to empty)
     */
    public function clear(): void;

    /**
     * Remove all storages from their drivers
     */
    public function remove(): void;

    /**
     * Get all storage keys tracked by this context
     *
     * @return array<string>
     */
    public function getTrackedKeys(): array;
}
