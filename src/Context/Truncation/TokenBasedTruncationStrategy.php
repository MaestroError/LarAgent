<?php

namespace LarAgent\Context\Truncation;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class TokenBasedTruncationStrategy extends TruncationStrategy
{
    /**
     * Get the default configuration for this strategy.
     *
     * @return array Default configuration
     */
    protected function defaultConfig(): array
    {
        return [
            'target_percentage' => 0.75, // Reduce to 75% of context window
            'preserve_system' => true, // Keep system/developer messages
        ];
    }

    /**
     * Apply truncation to messages array.
     * Removes early messages while tracking estimated prompt tokens.
     * Stops when estimated tokens reach target percentage of context window.
     *
     * @param  MessageArray  $messages  Current chat history
     * @param  int  $contextWindowSize  Maximum allowed tokens
     * @param  int  $currentTokens  Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        $targetPercentage = $this->getConfig('target_percentage', 0.75);
        $targetTokens = (int) ($contextWindowSize * $targetPercentage);

        // If we're already below target, no truncation needed
        if ($currentTokens <= $targetTokens) {
            return $messages;
        }

        $newMessages = new MessageArray();
        $allMessages = $messages->toArray();

        // Separate system messages and regular messages
        $systemMessages = [];
        $regularMessages = [];

        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }

        // Add system messages first (they're always preserved)
        $estimatedTokens = 0;
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
            $estimatedTokens += $this->estimateMessageTokens($message);
        }

        // Add regular messages from the end (most recent) while staying under target
        $regularMessages = array_reverse($regularMessages);
        foreach ($regularMessages as $message) {
            $messageTokens = $this->estimateMessageTokens($message);

            // Check if adding this message would exceed target
            if ($estimatedTokens + $messageTokens > $targetTokens) {
                break;
            }

            $newMessages->add($message);
            $estimatedTokens += $messageTokens;
        }

        // Reverse the regular messages to maintain chronological order
        // (system messages are first, then regular messages in order)
        $finalMessages = new MessageArray();
        $systemCount = count($systemMessages);
        $allNewMessages = $newMessages->toArray();

        // Add system messages
        for ($i = 0; $i < $systemCount; $i++) {
            $finalMessages->add($allNewMessages[$i]);
        }

        // Add regular messages in reverse (to restore chronological order)
        $regularNewMessages = array_slice($allNewMessages, $systemCount);
        $regularNewMessages = array_reverse($regularNewMessages);
        foreach ($regularNewMessages as $message) {
            $finalMessages->add($message);
        }

        return $finalMessages;
    }

    /**
     * Estimate token count for a message.
     * This is a rough estimate based on content length.
     *
     * @param  \LarAgent\Core\Contracts\Message  $message  The message to estimate
     * @return int Estimated token count
     */
    protected function estimateMessageTokens($message): int
    {
        // Check if message has usage metadata with prompt_tokens
        $metadata = $message->getMetadata();
        if (isset($metadata['usage']['prompt_tokens'])) {
            return (int) $metadata['usage']['prompt_tokens'];
        }

        // Check if message has getUsage method (AssistantMessage)
        if (method_exists($message, 'getUsage')) {
            $usage = $message->getUsage();
            if ($usage !== null && isset($usage->promptTokens)) {
                return $usage->promptTokens;
            }
        }

        // Fallback: rough estimate (1 token ≈ 4 characters)
        $content = $message->getContentAsString();

        return (int) ceil(strlen($content) / 4);
    }
}
