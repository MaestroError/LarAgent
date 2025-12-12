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
     * Important Notes on Token Estimation:
     * 1. For assistant messages: Uses completion_tokens when available, which accurately
     *    represents the tokens in the assistant's response.
     * 
     * 2. For other messages: Uses prompt_tokens when available, but this is an
     *    APPROXIMATION. The prompt_tokens value from an API response includes the
     *    total prompt (all previous messages + system prompt + formatting), not just
     *    the individual message's tokens.
     * 
     * 3. To avoid over-counting, this strategy should ideally be used when:
     *    - Messages store individual token counts (not full prompt tokens)
     *    - OR as a conservative approximation knowing it may trigger truncation earlier
     *    - OR when using character-based fallback estimation
     * 
     * 4. For most accurate token counting, consider:
     *    - Using SimpleTruncationStrategy if token precision is critical
     *    - Implementing a custom token counter (e.g., tiktoken)
     *    - Storing per-message token counts during message creation
     * 
     * Fallback: Uses rough estimate (1 token ≈ 4 characters) when no usage data available.
     *
     * @param  \LarAgent\Core\Contracts\Message  $message  The message to estimate
     * @return int Estimated token count
     */
    protected function estimateMessageTokens($message): int
    {
        // For assistant messages, use completion_tokens (accurate for the response)
        if ($message->getRole() === 'assistant') {
            if (method_exists($message, 'getUsage')) {
                $usage = $message->getUsage();
                if ($usage !== null && isset($usage->completionTokens)) {
                    return $usage->completionTokens;
                }
            }
            
            $metadata = $message->getMetadata();
            if (isset($metadata['usage']['completion_tokens'])) {
                return (int) $metadata['usage']['completion_tokens'];
            }
        }

        // For other messages, use prompt_tokens with caution (see note above)
        $metadata = $message->getMetadata();
        if (isset($metadata['usage']['prompt_tokens'])) {
            // This is an approximation - may include overhead from formatting
            return (int) $metadata['usage']['prompt_tokens'];
        }

        // Fallback: rough estimate (1 token ≈ 4 characters)
        // This is a conservative estimate and may not be accurate for all content types
        $content = $message->getContentAsString();

        return (int) ceil(strlen($content) / 4);
    }
}
