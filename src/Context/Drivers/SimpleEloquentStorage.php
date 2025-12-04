<?php

namespace LarAgent\Context\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use LarAgent\Context\Models\LaragentStorage;

class SimpleEloquentStorage extends StorageDriver
{
    /**
     * The Eloquent model class name.
     */
    protected string $model;

    /**
     * Create a new Simple Eloquent storage driver instance.
     *
     * @param  string|null  $model  The Eloquent model class to use for storage (defaults to LaragentStorage)
     */
    public function __construct(?string $model = null)
    {
        $this->model = $model ?? LaragentStorage::class;
    }

    /**
     * Read data from the database using Eloquent.
     *
     * @return array|null Returns null if no record found, data array otherwise
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $record = $this->model::where('key', $identity->getKey())->first();

        if (! $record) {
            return null;
        }

        return $record->data;
    }

    /**
     * Write data to the database using Eloquent.
     *
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            $this->model::updateOrCreate(
                ['key' => $identity->getKey()],
                ['data' => $data]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Remove data from the database.
     *
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $this->model::where('key', $identity->getKey())->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
