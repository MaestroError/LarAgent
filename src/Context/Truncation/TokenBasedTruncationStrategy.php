<?php

namespace LarAgent\Context\Truncation;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Core\Enums\Role;
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
        $allMessages = $messages->all();

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
     * Token Estimation Approach:
     * 1. For assistant messages: Uses completion_tokens from Usage DataModel when available,
     *    which accurately represents the tokens in the assistant's response.
     * 
     * 2. For all other messages (user, system, developer): Uses character-based estimation
     *    (1 token ≈ 4 characters). We intentionally do NOT use prompt_tokens because:
     *    - prompt_tokens includes the cumulative token count of ALL previous messages
     *    - Using it would cause massive over-counting when summing individual messages
     *    - Character-based estimation is more accurate for individual message sizing
     * 
     * 3. For most accurate token counting in production, consider:
     *    - Using SimpleTruncationStrategy if message-count-based truncation is sufficient
     *    - Implementing a custom token counter (e.g., tiktoken)
     *    - Storing per-message token counts during message creation
     *
     * @param  \LarAgent\Core\Contracts\Message  $message  The message to estimate
     * @return int Estimated token count
     */
    protected function estimateMessageTokens($message): int
    {
        // For assistant messages, use completion_tokens from Usage DataModel (accurate for the response)
        if ($message->getRole() === Role::ASSISTANT->value && method_exists($message, 'getUsage')) {
            $usage = $message->getUsage();
            if ($usage !== null && isset($usage->completionTokens)) {
                return $usage->completionTokens;
            }
        }

        // Character-based estimation for all messages without accurate token data
        // This is a conservative estimate (1 token ≈ 4 characters)
        $content = $message->getContentAsString();

        return (int) ceil(strlen($content) / 4);
    }
}
