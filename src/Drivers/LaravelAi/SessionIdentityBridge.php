<?php

namespace LarAgent\Drivers\LaravelAi;

use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\SessionIdentity;

/**
 * Bidirectional bridge between LarAgent's SessionIdentity and
 * Laravel AI SDK conversation concepts (userId, conversationId).
 *
 * This ensures both systems share the same session scope so storage,
 * identity tracking, and conversation context are unified.
 */
class SessionIdentityBridge
{
    /**
     * Extract the SDK-compatible userId from a LarAgent SessionIdentity.
     *
     * @return string|int|null The user ID, or null if identity is not user-scoped
     */
    public static function toSdkUserId(SessionIdentityContract $identity): string|int|null
    {
        return $identity->getUserId();
    }

    /**
     * Extract the SDK-compatible conversationId from a LarAgent SessionIdentity.
     * Uses the identity's composite key as the unique conversation identifier.
     */
    public static function toSdkConversationId(SessionIdentityContract $identity): string
    {
        return $identity->getKey();
    }

    /**
     * Build a LarAgent SessionIdentity from SDK conversation parameters.
     *
     * @param  string  $conversationId  The SDK conversation ID (used as chatName)
     * @param  string|int|null  $userId  The SDK user ID
     * @param  string  $agentName  The LarAgent agent class name
     */
    public static function fromSdkConversation(
        string $conversationId,
        string|int|null $userId,
        string $agentName
    ): SessionIdentityContract {
        return new SessionIdentity(
            agentName: $agentName,
            chatName: $conversationId,
            userId: $userId !== null ? (string) $userId : null,
        );
    }

    /**
     * Convert a SessionIdentity to an array of SDK-compatible conversation parameters.
     *
     * @return array{conversationId: string, userId: string|int|null}
     */
    public static function toConversable(SessionIdentityContract $identity): array
    {
        return [
            'conversationId' => static::toSdkConversationId($identity),
            'userId' => static::toSdkUserId($identity),
        ];
    }

    /**
     * Create a round-trip test: convert identity to SDK params and back.
     * The returned identity preserves the original agent name and user ID,
     * while the chatName becomes the original composite key.
     *
     * @return SessionIdentityContract A new identity reconstructed from SDK params
     */
    public static function roundTrip(SessionIdentityContract $identity, string $agentName): SessionIdentityContract
    {
        $sdkParams = static::toConversable($identity);

        return static::fromSdkConversation(
            $sdkParams['conversationId'],
            $sdkParams['userId'],
            $agentName
        );
    }
}
