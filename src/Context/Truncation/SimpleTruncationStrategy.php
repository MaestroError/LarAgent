<?php

namespace LarAgent\Context\Truncation;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class SimpleTruncationStrategy extends TruncationStrategy
{
    /**
     * Get the default configuration for this strategy.
     *
     * @return array Default configuration
     */
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 10, // Number of recent messages to keep
            'preserve_system' => true, // Keep system/developer messages
        ];
    }

    /**
     * Apply truncation to messages array.
     * Removes early messages while keeping the last N messages.
     * Always preserves system/developer messages at the beginning.
     *
     * @param  MessageArray  $messages  Current chat history
     * @param  int  $truncationThreshold  Maximum allowed tokens (effective threshold after buffer)
     * @param  int  $currentTokens  Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $truncationThreshold, int $currentTokens): MessageArray
    {
        $keepMessages = $this->getConfig('keep_messages', 10);

        // If we have fewer messages than keep_messages, no truncation needed
        if ($messages->count() <= $keepMessages) {
            return $messages;
        }

        $newMessages = new MessageArray;
        $allMessages = $messages->all();

        // First, collect all system/developer messages to preserve
        $systemMessages = [];
        $regularMessages = [];

        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }

        // Add preserved messages first
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }

        // Keep only the last N regular messages
        $messagesToKeep = array_slice($regularMessages, -$keepMessages);
        foreach ($messagesToKeep as $message) {
            $newMessages->add($message);
        }

        return $newMessages;
    }
}
