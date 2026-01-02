<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\ToolCall;

/**
 * OpenAI Message Formatter
 *
 * This formatter handles conversion between LarAgent message objects and
 * OpenAI-compatible array format. Since OpenAI format is the canonical
 * internal format, most operations are pass-through.
 */
class OpenAiMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to OpenAI-compatible array format.
     */
    public function formatMessage(MessageInterface $message): array
    {
        // Handle specific message types that need special formatting
        if ($message instanceof ToolResultMessage) {
            return $this->formatToolResultMessage($message);
        }

        if ($message instanceof ToolCallMessage) {
            return $this->formatToolCallMessage($message);
        }

        if ($message instanceof UserMessage) {
            return $this->formatUserMessage($message);
        }

        // For System, Developer, Assistant messages - simple format
        return [
            'role' => $message->getRole(),
            'content' => $message->getContentAsString(),
        ];
    }

    /**
     * Convert an array of LarAgent messages to OpenAI-compatible format.
     */
    public function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $message) {
            $formatted[] = $this->formatMessage($message);
        }

        return $formatted;
    }

    /**
     * Convert LarAgent tools to OpenAI-compatible format.
     */
    public function formatTools(array $tools): array
    {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = $this->formatTool($tool);
        }

        return $formatted;
    }

    // ========== Driver → LarAgent (extracting from API response) ==========

    /**
     * Extract usage/token information from OpenAI response.
     */
    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ];
    }

    /**
     * Extract tool calls from OpenAI response.
     * Returns array of ToolCall objects.
     */
    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];

        $responseToolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];

        foreach ($responseToolCalls as $toolCall) {
            $toolCalls[] = new ToolCall(
                $toolCall['id'],
                $toolCall['function']['name'],
                $toolCall['function']['arguments']
            );
        }

        return $toolCalls;
    }

    /**
     * Extract text content from OpenAI response.
     */
    public function extractContent(array $response): string
    {
        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Extract finish reason from OpenAI response.
     * Returns normalized values: 'stop', 'tool_calls', 'length', 'content_filter'
     */
    public function extractFinishReason(array $response): string
    {
        $reason = $response['choices'][0]['finish_reason'] ?? 'stop';

        // OpenAI uses these values directly, no normalization needed
        return $reason;
    }

    // ========== Helper methods ==========

    /**
     * Format a UserMessage, handling multimodal content (text, images, audio).
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = $message->getContent();

        // Handle null content
        if ($content === null) {
            return [
                'role' => 'user',
                'content' => '',
            ];
        }

        return [
            'role' => 'user',
            'content' => $content->toArray(),
        ];
    }

    /**
     * Format a ToolCallMessage for OpenAI.
     */
    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        $toolCallsArray = [];

        foreach ($message->getToolCalls() as $toolCall) {
            $toolCallsArray[] = [
                'id' => $toolCall->getId(),
                'type' => 'function',
                'function' => [
                    'name' => $toolCall->getToolName(),
                    'arguments' => $toolCall->getArguments(),
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCallsArray,
        ];
    }

    /**
     * Format a ToolResultMessage for OpenAI.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'tool',
            'content' => $message->getContentAsString(),
            'tool_call_id' => $message->getToolCallId(),
        ];
    }

    /**
     * Format a single tool for OpenAI payload.
     */
    protected function formatTool(ToolInterface $tool): array
    {
        $toolSchema = [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
            ],
        ];

        if (! empty($tool->getProperties())) {
            $toolSchema['function']['parameters'] = [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }
}
