<?php

namespace LarAgent\Context\Truncation;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;

class SummarizationStrategy extends TruncationStrategy
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
            'summary_agent' => null, // Agent class name for summarization (required)
            'summary_title' => 'Summary of previous conversation',
            'preserve_system' => true, // Keep system/developer messages
        ];
    }

    /**
     * Apply truncation to messages array.
     * Keeps system messages and last N messages, summarizes the middle messages.
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

        if ($summaryAgentClass === null) {
            throw new \InvalidArgumentException('SummarizationStrategy requires a summary_agent configuration');
        }

        // If we have fewer messages than keep_messages, no truncation needed
        if ($messages->count() <= $keepMessages) {
            return $messages;
        }

        $allMessages = $messages->toArray();

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

        // Split regular messages: keep last N, summarize the rest
        $totalRegular = count($regularMessages);
        if ($totalRegular <= $keepMessages) {
            // No need to summarize, just return all messages
            return $messages;
        }

        $middleMessages = array_slice($regularMessages, 0, $totalRegular - $keepMessages);
        $recentMessages = array_slice($regularMessages, $totalRegular - $keepMessages);

        // Generate summary of middle messages
        $summary = $this->summarizeMessages($middleMessages, $summaryAgentClass);

        // Build new message array
        $newMessages = new MessageArray();

        // Add system messages first
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }

        // Add summary as developer message
        if (! empty($summary)) {
            $summaryMessage = Message::developer(
                $this->getConfig('summary_title').': '.$summary
            );
            $newMessages->add($summaryMessage);
        }

        // Add recent messages
        foreach ($recentMessages as $message) {
            $newMessages->add($message);
        }

        return $newMessages;
    }

    /**
     * Summarize an array of messages using the provided agent.
     *
     * @param  array  $messages  Messages to summarize
     * @param  string  $agentClass  The agent class to use for summarization
     * @return string The summary
     */
    protected function summarizeMessages(array $messages, string $agentClass): string
    {
        if (empty($messages)) {
            return '';
        }

        // Build a text representation of the messages
        $conversationText = '';
        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContentAsString();
            $conversationText .= "{$role}: {$content}\n\n";
        }

        // Create agent instance and get summary
        try {
            // Verify agent class exists and has the make method
            if (! class_exists($agentClass)) {
                throw new \InvalidArgumentException("Agent class {$agentClass} does not exist");
            }

            if (! method_exists($agentClass, 'make')) {
                throw new \InvalidArgumentException("Agent class {$agentClass} must have a static 'make' method");
            }

            $agent = $agentClass::make();
            $prompt = "Please provide a concise summary of the following conversation:\n\n{$conversationText}";
            $summary = $agent->respond($prompt);

            // Convert to string if needed
            if (is_array($summary)) {
                $summary = json_encode($summary);
            }

            return (string) $summary;
        } catch (\Throwable $e) {
            // If summarization fails, return a basic summary
            // Log the error if logging is available
            if (function_exists('logger')) {
                logger()->warning("Truncation summarization failed: {$e->getMessage()}");
            }

            return 'Previous conversation contained '.count($messages).' messages.';
        }
    }
}
