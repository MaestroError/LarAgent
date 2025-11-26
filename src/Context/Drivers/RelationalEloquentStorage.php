<?php

namespace LarAgent\Context\Drivers;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * CONCEPTUAL
 */
class RelationalEloquentStorage extends StorageDriver
{
    /**
     * The Eloquent model class for the Chat/Session.
     * 
     * @var string
     */
    protected string $sessionModel;

    /**
     * The name of the relation on the Session model that points to Messages.
     * 
     * @var string
     */
    protected string $messageRelation;

    /**
     * Create a new Relational Eloquent storage driver instance.
     *
     * @param string $sessionModel The Eloquent model class for the session (e.g., ChatHistory).
     * @param string $messageRelation The name of the relation to messages (e.g., 'messages').
     */
    public function __construct(string $sessionModel, string $messageRelation = 'messages')
    {
        $this->sessionModel = $sessionModel;
        $this->messageRelation = $messageRelation;
    }

    /**
     * Read data from the database using Eloquent relations.
     *
     * @param SessionIdentity $identity
     * @return array|null Returns null if no session found, messages array otherwise
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        // Find the session and eager load messages
        $session = $this->sessionModel::where('key', $identity->getKey())
            ->with($this->messageRelation)
            ->first();

        if (!$session) {
            return null;
        }

        // Transform related messages to array
        // Assuming the Message model attributes match the expected array structure
        return $session->{$this->messageRelation}->map(function (Model $message) {
            return $message->toArray();
        })->toArray();
    }

    /**
     * Write data to the database using Eloquent relations.
     *
     * @param SessionIdentity $identity
     * @param array $data
     * @return bool True if written successfully, false if writing failed
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        try {
            // Find or create the session
            $session = $this->sessionModel::updateOrCreate(
                ['key' => $identity->getKey()],
                [
                    'agent_name' => $identity->getAgentName(),
                    'chat_name' => $identity->getChatName(),
                    'user_id' => $identity->getUserId(),
                    'group' => $identity->getGroup(),
                ]
            );

            // Use a transaction for data integrity
            DB::transaction(function () use ($session, $data) {
                // 1. Delete existing messages (1 query)
                $session->{$this->messageRelation}()->delete();

                if (!empty($data)) {
                    // 2. Prepare for bulk insert (Optimization over createMany)
                    $records = [];
                    $now = now();
                    
                    foreach ($data as $item) {
                        // Create a model instance to properly resolve FK and default attributes
                        // This respects $fillable/$guarded properties of the model
                        $model = $session->{$this->messageRelation}()->make($item);
                        
                        // Manually add timestamps since insert() skips them
                        if ($model->usesTimestamps()) {
                            $model->setCreatedAt($now);
                            $model->setUpdatedAt($now);
                        }
                        
                        $records[] = $model->getAttributes();
                    }

                    // 3. Bulk insert (1 query)
                    $session->{$this->messageRelation}()->insert($records);
                }
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Remove session and all related messages from the database.
     *
     * @param SessionIdentity $identity
     * @return bool True if removed successfully, false if removal failed
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        try {
            $session = $this->sessionModel::where('key', $identity->getKey())->first();

            if ($session) {
                // Delete related messages first (if not using cascade)
                $session->{$this->messageRelation}()->delete();
                // Delete the session
                $session->delete();
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
