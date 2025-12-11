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

        // Calculate tokens for system messages (always preserved)
        $estimatedTokens = 0;
        foreach ($systemMessages as $message) {
            $estimatedTokens += $this->estimateMessageTokens($message);
        }

        // Add regular messages from most recent to oldest, staying under target
        // Build array in correct order to avoid multiple reversals
        $keptRegularMessages = [];
        for ($i = count($regularMessages) - 1; $i >= 0; $i--) {
            $message = $regularMessages[$i];
            $messageTokens = $this->estimateMessageTokens($message);

            // Check if adding this message would exceed target
            if ($estimatedTokens + $messageTokens > $targetTokens) {
                break;
            }

            // Add to front of array (since we're iterating backwards)
            array_unshift($keptRegularMessages, $message);
            $estimatedTokens += $messageTokens;
        }

        // Build final message array: system messages first, then regular messages in chronological order
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }
        foreach ($keptRegularMessages as $message) {
            $newMessages->add($message);
        }

        return $newMessages;
    }

    /**
     * Estimate token count for a message.
     * 
     * Note: This uses promptTokens from usage metadata when available, which represents
     * the tokens used for this message in the API request, not necessarily the exact
     * token count of the message content alone. This is an approximation and may
     * include additional tokens from formatting, system prompts, etc.
     * 
     * For messages without usage data, falls back to a rough estimate based on
     * character count (1 token ≈ 4 characters).
     *
     * @param  \LarAgent\Core\Contracts\Message  $message  The message to estimate
     * @return int Estimated token count
     */
    protected function estimateMessageTokens($message): int
    {
        // Check if message has usage metadata with prompt_tokens
        // Note: This is an approximation as prompt_tokens includes the full prompt
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
        // This is a conservative estimate and may not be accurate for all content types
        $content = $message->getContentAsString();

        return (int) ceil(strlen($content) / 4);
    }
}
