<?php

namespace LarAgent\Context\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * CONCEPTUAL
 */
class EloquentStorage extends StorageDriver
{
    /**
     * The Eloquent model class name.
     * 
     * @var string
     */
    protected string $model;

    /**
     * Create a new Eloquent storage driver instance.
     *
     * @param string $model The Eloquent model class to use for storage.
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * Read data from the database using Eloquent.
     *
     * @param SessionIdentity $identity
     * @return array
     */
    public function readFromMemory(SessionIdentity $identity): array
    {
        $record = $this->model::where('key', $identity->getKey())->first();

        if (!$record) {
            return [];
        }

        // Assuming the model casts 'payload' to array or we decode it here
        return $record->payload ?? [];
    }

    /**
     * Write data to the database using Eloquent.
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return void
     */
    public function writeToMemory(SessionIdentity $identity, array $data): void
    {
        $this->model::updateOrCreate(
            ['key' => $identity->getKey()],
            [
                'payload' => $data,
                // Optional: Store individual fields for easier querying/debugging
                'agent_name' => $identity->getAgentName(),
                'chat_name' => $identity->getChatName(),
                'user_id' => $identity->getUserId(),
                'group' => $identity->getGroup(),
            ]
        );
    }
}
