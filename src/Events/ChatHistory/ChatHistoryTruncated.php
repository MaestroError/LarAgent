<?php

namespace LarAgent\Events\ChatHistory;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Messages\DataModels\MessageArray;

/**
 * Event dispatched after chat history truncation is applied.
 */
class ChatHistoryTruncated
{
    /**
     * Create a new ChatHistoryTruncated event instance.
     *
     * @param  ChatHistoryStorage  $chatHistory  The chat history storage instance
     * @param  MessageArray  $truncatedMessages  The truncated messages
     */
    public function __construct(
        public readonly ChatHistoryStorage $chatHistory,
        public readonly MessageArray $truncatedMessages
    ) {
    }
}
