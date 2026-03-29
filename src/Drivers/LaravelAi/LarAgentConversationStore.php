<?php

namespace LarAgent\Drivers\LaravelAi;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LarAgent\Context\Context;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Messages\UserMessage;

/**
 * Implements the Laravel AI SDK's ConversationStore interface backed by
 * LarAgent's ChatHistoryStorage, making LarAgent's storage the single
 * source of truth for conversation persistence.
 *
 * This bridge enables the SDK's RememberConversation middleware to
 * write through LarAgent's storage drivers (Cache, Eloquent, File, etc.)
 * instead of maintaining a separate persistence layer.
 */
class LarAgentConversationStore
{
    /**
     * The agent name used to build session identities.
     */
    protected string $agentName;

    /**
     * Storage driver configuration for creating new ChatHistoryStorage instances.
     */
    protected array $driversConfig;

    /**
     * Cached context instances keyed by conversation ID.
     *
     * @var array<string, Context>
     */
    protected array $contexts = [];

    public function __construct(string $agentName, array $driversConfig = [])
    {
        $this->agentName = $agentName;
        $this->driversConfig = $driversConfig;
    }

    /**
     * Find the latest conversation ID for a user.
     * Queries IdentityStorage for the most recent chat key.
     *
     * @param  string|int  $userId  The user identifier
     * @return string|null The conversation ID, or null if no conversations exist
     */
    public function latestConversationId(string|int $userId): ?string
    {
        $context = $this->resolveContextForUser((string) $userId);

        // storeConversation() stores the userId in the identity's group field
        // (not userId), so we must filter by group to find matching conversations.
        $identities = $context->getIdentityStorage()->getIdentitiesByGroup((string) $userId);

        if ($identities->isEmpty()) {
            return null;
        }

        // Return the last tracked identity key as the latest conversation
        $allIdentities = $identities->all();
        $latest = end($allIdentities);

        if ($latest === false || ! ($latest instanceof SessionIdentityContract)) {
            return null;
        }

        return $latest->getKey();
    }

    /**
     * Store a new conversation and return its ID.
     *
     * The chatName (UUID) is used as the primary key component to ensure
     * each conversation gets a unique ID, even for the same user.
     * The userId is stored as the group for association without
     * overriding key uniqueness.
     *
     * @param  string|int|null  $userId  The user identifier
     * @param  string  $title  The conversation title (stored as metadata)
     * @return string The new conversation ID
     */
    public function storeConversation(string|int|null $userId, string $title): string
    {
        $chatName = Str::uuid()->toString();

        // Use chatName (UUID) as the unique key component.
        // UserId is stored in the group field for association lookups
        // without overriding key uniqueness (key = agentName_chatName).
        $identity = new SessionIdentity(
            agentName: $this->agentName,
            chatName: $chatName,
            userId: null,
            group: $userId !== null ? (string) $userId : null,
        );

        $context = new Context($identity, $this->driversConfig);
        $chatHistory = new ChatHistoryStorage($identity, $this->driversConfig);
        $context->register($chatHistory);

        // Persist the identity so it can be found by latestConversationId()
        $context->getIdentityStorage()->save();

        // Track the context for later use
        $conversationId = $identity->getKey();
        $this->contexts[$conversationId] = $context;

        return $conversationId;
    }

    /**
     * Store a user message in a conversation.
     *
     * @param  string  $conversationId  The conversation identifier
     * @param  string|int|null  $userId  The user identifier
     * @param  object  $prompt  The SDK AgentPrompt containing the message
     * @return string The message identifier
     */
    public function storeUserMessage(string $conversationId, string|int|null $userId, object $prompt): string
    {
        $chatHistory = $this->resolveChatHistory($conversationId, $userId);

        $content = $this->extractPromptContent($prompt);
        $message = new UserMessage($content);

        $chatHistory->addMessage($message);
        $chatHistory->save();

        return $message->message_uuid ?? Str::uuid()->toString();
    }

    /**
     * Store an assistant message in a conversation.
     *
     * @param  string  $conversationId  The conversation identifier
     * @param  string|int|null  $userId  The user identifier
     * @param  object  $prompt  The SDK AgentPrompt
     * @param  object  $response  The SDK AgentResponse
     * @return string The message identifier
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, object $prompt, object $response): string
    {
        $chatHistory = $this->resolveChatHistory($conversationId, $userId);

        // Store intermediate tool messages if present
        $intermediateMessages = MessageConverter::extractIntermediateMessages($response);
        foreach ($intermediateMessages as $intermediateMsg) {
            $chatHistory->addMessage($intermediateMsg);
        }

        // Store the final assistant message with aggregated usage
        $assistantMessage = MessageConverter::fromSdkResponse($response);
        $chatHistory->addMessage($assistantMessage);
        $chatHistory->save();

        return $assistantMessage->message_uuid ?? Str::uuid()->toString();
    }

    /**
     * Get the latest messages from a conversation.
     *
     * @param  string  $conversationId  The conversation identifier
     * @param  int  $limit  Maximum number of messages to return
     * @return Collection Collection of messages
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $chatHistory = $this->resolveChatHistory($conversationId);

        $messages = $chatHistory->getMessages()->all();
        $sliced = array_slice($messages, -$limit);

        return collect($sliced);
    }

    /**
     * Get the underlying ChatHistoryStorage for a conversation.
     * Useful for direct access when needed by other LarAgent components.
     */
    public function getChatHistory(string $conversationId, string|int|null $userId = null): ChatHistoryStorage
    {
        return $this->resolveChatHistory($conversationId, $userId);
    }

    /**
     * Resolve or create a ChatHistoryStorage for the given conversation.
     */
    protected function resolveChatHistory(string $conversationId, string|int|null $userId = null): ChatHistoryStorage
    {
        // Check if we have a cached context for this conversation
        if (isset($this->contexts[$conversationId])) {
            $context = $this->contexts[$conversationId];
            $storage = $context->getStorage(ChatHistoryStorage::class);
            if ($storage instanceof ChatHistoryStorage) {
                return $storage;
            }
        }

        // Build identity from conversation ID
        $identity = SessionIdentityBridge::fromSdkConversation(
            $conversationId,
            $userId,
            $this->agentName
        );

        $chatHistory = new ChatHistoryStorage($identity, $this->driversConfig);
        $chatHistory->read();

        // Cache the context
        $context = new Context($identity, $this->driversConfig);
        $context->register($chatHistory);
        $this->contexts[$conversationId] = $context;

        return $chatHistory;
    }

    /**
     * Resolve a Context for a user (used for identity lookups).
     */
    protected function resolveContextForUser(string $userId): Context
    {
        $identity = new SessionIdentity(
            agentName: $this->agentName,
            userId: $userId,
        );

        $context = new Context($identity, $this->driversConfig);
        $context->getIdentityStorage()->read();

        return $context;
    }

    /**
     * Extract text content from an SDK prompt object.
     */
    protected function extractPromptContent(object $prompt): string
    {
        if (isset($prompt->text) && is_string($prompt->text)) {
            return $prompt->text;
        }

        if (isset($prompt->content) && is_string($prompt->content)) {
            return $prompt->content;
        }

        if (method_exists($prompt, '__toString')) {
            return (string) $prompt;
        }

        return '';
    }
}
