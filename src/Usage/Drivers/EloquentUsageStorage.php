<?php

namespace LarAgent\Usage\Drivers;

use Illuminate\Support\Facades\DB;
use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use LarAgent\Usage\Models\LaragentUsage;

class EloquentUsageStorage extends StorageDriver
{
    /**
     * The Eloquent model class name.
     */
    protected string $model;

    /**
     * Create a new Eloquent usage storage driver instance.
     *
     * @param  string|null  $model  The Eloquent model class to use for storage
     */
    public function __construct(?string $model = null)
    {
        $this->model = $model ?? LaragentUsage::class;
    }

    /**
     * Read all usage items from the database.
     * For usage storage, we don't filter by session key since usage is tracked separately.
     *
     * @return array|null Returns null if no records found, array of items otherwise
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        // For usage, we can still scope by identity fields if needed
        // but typically usage is tracked at a broader level
        $query = $this->model::query();

        // Optionally filter by identity attributes
        if ($identity->getUserId()) {
            $query->where('user_id', $identity->getUserId());
        }
        if ($identity->getGroup()) {
            $query->where('group', $identity->getGroup());
        }
        if ($identity->getChatName()) {
            $query->where('chat_name', $identity->getChatName());
        }

        $records = $query->orderBy('created_at', 'desc')->get();

        if ($records->isEmpty()) {
            return null;
        }

        // Convert each model to array
        return $records->map(function ($record) {
            return $record->only([
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
                'user_id',
                'group',
                'chat_name',
                'model',
                'provider',
                'agent',
                'created_at',
            ]);
        })->all();
    }

    /**
     * Write usage items to the database.
     * Each item is a separate usage record, so we append rather than replace.
     *
     * @param  array  $data  Array of usage items to store
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            DB::transaction(function () use ($data) {
                if (empty($data)) {
                    return;
                }

                $records = [];
                $now = now();

                foreach ($data as $item) {
                    $model = new $this->model;
                    $model->fill($item);
                    
                    // Ensure timestamps
                    if (!isset($item['created_at'])) {
                        $model->created_at = $now;
                    }
                    $model->updated_at = $now;

                    $records[] = $model->getAttributes();
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
     * Remove usage items from the database.
     * For usage storage, we typically don't remove items as they're historical data.
     *
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $query = $this->model::query();

            // Filter by identity attributes
            if ($identity->getUserId()) {
                $query->where('user_id', $identity->getUserId());
            }
            if ($identity->getGroup()) {
                $query->where('group', $identity->getGroup());
            }
            if ($identity->getChatName()) {
                $query->where('chat_name', $identity->getChatName());
            }

            $query->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
