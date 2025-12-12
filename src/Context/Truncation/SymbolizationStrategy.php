<?php

namespace LarAgent\Context\Truncation;

use LarAgent\BuiltIn\Agents\ChatSymbolizerAgent;
use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;

class SymbolizationStrategy extends TruncationStrategy
{
    /**
     * Get the default configuration for this strategy.
     *
     * @return array Default configuration
     */
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 5, // Number of recent messages to keep
            'summary_agent' => ChatSymbolizerAgent::class, // Agent for individual message summarization
            'symbol_title' => 'Conversation symbols',
            'preserve_system' => true, // Keep system/developer messages
        ];
    }

    /**
     * Apply truncation to messages array.
     * Keeps system messages and last N messages.
     * Creates brief symbols/summaries for each middle message and combines them.
     *
     * @param  MessageArray  $messages  Current chat history
     * @param  int  $contextWindowSize  Maximum allowed tokens
     * @param  int  $currentTokens  Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        $keepMessages = $this->getConfig('keep_messages', 5);
        $summaryAgentClass = $this->getConfig('summary_agent');

        // If we have fewer messages than keep_messages, no truncation needed
        if ($messages->count() <= $keepMessages) {
            return $messages;
        }

        $allMessages = $messages->all();

        // Separate messages into categories
        $systemMessages = [];
        $middleMessages = [];
        $recentMessages = [];

        $regularMessages = [];

        // First pass: separate system messages from regular messages
        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }

        // Split regular messages: keep last N, symbolize the rest
        $totalRegular = count($regularMessages);
        if ($totalRegular <= $keepMessages) {
            // No need to symbolize, just return all messages
            return $messages;
        }

        $middleMessages = array_slice($regularMessages, 0, $totalRegular - $keepMessages);
        $recentMessages = array_slice($regularMessages, $totalRegular - $keepMessages);

        // Generate symbols for middle messages
        $symbols = $this->symbolizeMessages($middleMessages, $summaryAgentClass);

        // Build new message array
        $newMessages = new MessageArray();

        // Add system messages first
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }

        // Add symbols as developer message
        if (! empty($symbols)) {
            $symbolsText = $this->getConfig('symbol_title').":\n".$symbols;
            $symbolMessage = Message::developer($symbolsText);
            $newMessages->add($symbolMessage);
        }

        // Add recent messages
        foreach ($recentMessages as $message) {
            $newMessages->add($message);
        }

        return $newMessages;
    }

    /**
     * Create symbols/brief summaries for an array of messages.
     *
     * @param  array  $messages  Messages to symbolize
     * @param  string  $agentClass  The agent class to use for symbolization
     * @return string The combined symbols
     */
    protected function symbolizeMessages(array $messages, string $agentClass): string
    {
        if (empty($messages)) {
            return '';
        }

        $symbols = [];

        foreach ($messages as $index => $message) {
            $role = $message->getRole();
            $content = $message->getContentAsString();

            // Create a brief symbol/summary for this message
            $symbol = $this->createSymbol($role, $content, $agentClass, $index);
            $symbols[] = $symbol;
        }

        return implode("\n", $symbols);
    }

    /**
     * Create a symbol/brief summary for a single message.
     *
     * @param  string  $role  Message role
     * @param  string  $content  Message content
     * @param  string  $agentClass  The agent class to use
     * @param  int  $index  Message index
     * @return string The symbol/brief summary
     */
    protected function createSymbol(string $role, string $content, string $agentClass, int $index): string
    {
        if ($role == 'assistant') {
            $role = 'You';
        } elseif ($role == 'user') {
            $role = 'User';
        }

        try {
            // Verify agent class exists and has the make method
            if (! class_exists($agentClass)) {
                throw new \InvalidArgumentException("Agent class {$agentClass} does not exist");
            }

            if (! method_exists($agentClass, 'make')) {
                throw new \InvalidArgumentException("Agent class {$agentClass} must have a static 'make' method");
            }

            $agent = $agentClass::make();
            $prompt = "Create a very brief 1-sentence symbol/summary for this {$role} message: {$content}";
            $symbol = $agent->respond($prompt);

            // Convert to string if needed
            if (is_array($symbol)) {
                $symbol = json_encode($symbol);
            }

            return "- [{$role}] ".(string) $symbol;
        } catch (\Throwable $e) {
            // If symbolization fails, create a basic symbol
            // Log the error if logging is available
            try {
                if (function_exists('logger') && app()->bound('log')) {
                    logger()->warning("Truncation symbolization failed: {$e->getMessage()}");
                }
            } catch (\Throwable $logError) {
                // Ignore logging errors
            }

            $preview = substr($content, 0, 50);
            if (strlen($content) > 50) {
                $preview .= '...';
            }

            return "- [{$role}] {$preview}";
        }
    }
}
