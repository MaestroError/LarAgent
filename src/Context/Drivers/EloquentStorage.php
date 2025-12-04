<?php

namespace LarAgent\Context\Drivers;

use Illuminate\Support\Facades\DB;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use LarAgent\Context\Models\LaragentMessage;

class EloquentStorage extends StorageDriver
{
    /**
     * The Eloquent model class name.
     */
    protected string $model;

    /**
     * The column name for the session key.
     */
    protected string $keyColumn = 'session_key';

    /**
     * The column name for the position/order.
     */
    protected string $positionColumn = 'position';

    /**
     * Create a new Eloquent storage driver instance.
     *
     * @param string|null $model The Eloquent model class to use for storage
     */
    public function __construct(?string $model = null)
    {
        $this->model = $model ?? LaragentMessage::class;
    }

    /**
     * Read all items from the database for this session.
     * Returns items ordered by position to maintain array order.
     *
     * @param SessionIdentity $identity
     * @return array|null Returns null if no records found, array of items otherwise
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $records = $this->model::where($this->keyColumn, $identity->getKey())
            ->orderBy($this->positionColumn)
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        // Convert each model to array, excluding internal columns
        $internalFields = ['id', $this->keyColumn, $this->positionColumn, 'created_at', 'updated_at'];

        return $records->map(function ($record) use ($internalFields) {
            return collect($record->toArray())
                ->except($internalFields)
                ->filter(fn ($value) => $value !== null)
                ->all();
        })->all();
    }

    /**
     * Write items to the database using a transaction.
     * Deletes all existing items for this session and bulk inserts the new items.
     *
     * @param SessionIdentity $identity
     * @param array $data Array of items to store
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            DB::transaction(function () use ($identity, $data) {
                // Delete all existing items for this session
                $this->model::where($this->keyColumn, $identity->getKey())->delete();

                if (empty($data)) {
                    return;
                }

                // Build records array using fill() for safe attribute assignment
                $records = [];
                $now = now();

                // Get all fillable columns from model to ensure consistent record structure
                $model = new $this->model();
                $fillableColumns = $model->getFillable();

                foreach ($data as $position => $item) {
                    $model = new $this->model();
                    $model->fill($item);
                    $model->{$this->keyColumn} = $identity->getKey();
                    $model->{$this->positionColumn} = $position;
                    $model->created_at = $now;
                    $model->updated_at = $now;

                    // Get attributes and ensure all fillable columns are present
                    $record = $model->getAttributes();
                    foreach ($fillableColumns as $column) {
                        if (!array_key_exists($column, $record)) {
                            $record[$column] = null;
                        }
                    }

                    $records[] = $record;
                }

                // Bulk insert all records in a single query
                $this->model::insert($records);
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Remove all items from the database for this session.
     *
     * @param SessionIdentity $identity
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $this->model::where($this->keyColumn, $identity->getKey())->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set a custom key column name.
     *
     * @param string $column
     * @return static
     */
    public function setKeyColumn(string $column): static
    {
        $this->keyColumn = $column;
        return $this;
    }

    /**
     * Set a custom position column name.
     *
     * @param string $column
     * @return static
     */
    public function setPositionColumn(string $column): static
    {
        $this->positionColumn = $column;
        return $this;
    }
}
