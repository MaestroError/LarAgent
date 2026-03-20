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
 * Message formatter for the OpenAI Responses API.
 *
 * Converts between LarAgent message objects and the Responses API
 * input/output format, which differs from Chat Completions.
 */
class OpenAiResponsesMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to Responses API input item format.
     *
     * Note: ToolCallMessage returns multiple items (one per tool call),
     * so formatMessages() handles flattening.
     */
    public function formatMessage(MessageInterface $message): array
    {
        if ($message instanceof ToolResultMessage) {
            return $this->formatToolResultMessage($message);
        }

        if ($message instanceof ToolCallMessage) {
            return $this->formatToolCallMessage($message);
        }

        if ($message instanceof UserMessage) {
            return $this->formatUserMessage($message);
        }

        // System, Developer, Assistant messages
        $role = $message->getRole();
        $contentType = ($role === 'assistant') ? 'output_text' : 'input_text';

        return [
            'type' => 'message',
            'role' => $role,
            'content' => [
                ['type' => $contentType, 'text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Convert an array of LarAgent messages to Responses API input format.
     *
     * ToolCallMessages are flattened into separate function_call items.
     */
    public function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                // Each tool call becomes a separate function_call input item
                foreach ($message->getToolCalls() as $toolCall) {
                    $formatted[] = [
                        'type' => 'function_call',
                        'call_id' => $toolCall->getId(),
                        'name' => $toolCall->getToolName(),
                        'arguments' => $toolCall->getArguments(),
                    ];
                }
            } else {
                $formatted[] = $this->formatMessage($message);
            }
        }

        return $formatted;
    }

    /**
     * Convert LarAgent tools to Responses API flat tool format.
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
     * Extract usage from Responses API response.
     * Maps input_tokens/output_tokens to normalized keys.
     */
    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
        ];
    }

    /**
     * Extract tool calls from Responses API response.
     * Filters output items for type === 'function_call'.
     */
    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        $output = $response['output'] ?? [];

        foreach ($output as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['call_id'],
                    $item['name'],
                    $item['arguments']
                );
            }
        }

        return $toolCalls;
    }

    /**
     * Extract text content from Responses API response.
     * Uses the convenience output_text field.
     */
    public function extractContent(array $response): string
    {
        return $response['output_text'] ?? '';
    }

    /**
     * Extract and normalize finish reason from Responses API response.
     *
     * Detection: if output contains function_call items -> 'tool_calls',
     * otherwise map status: 'completed' -> 'stop', 'incomplete' -> 'length'.
     */
    public function extractFinishReason(array $response): string
    {
        // Check if output contains function_call items
        $output = $response['output'] ?? [];
        foreach ($output as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                return 'tool_calls';
            }
        }

        // Map status to normalized finish reason
        $status = $response['status'] ?? 'completed';

        return match ($status) {
            'completed' => 'stop',
            'incomplete' => 'length',
            'failed' => 'stop',
            default => 'stop',
        };
    }

    // ========== Helper methods ==========

    protected function formatUserMessage(UserMessage $message): array
    {
        $content = $message->getContent();

        if ($content === null) {
            return [
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => ''],
                ],
            ];
        }

        // For multimodal content, convert to array and wrap text parts
        $contentArray = $content->toArray();
        if (is_string($contentArray)) {
            return [
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $contentArray],
                ],
            ];
        }

        // Map content parts to Responses API format
        $parts = [];
        foreach ($contentArray as $part) {
            if (is_string($part)) {
                $parts[] = ['type' => 'input_text', 'text' => $part];
            } elseif (isset($part['type']) && $part['type'] === 'text') {
                $parts[] = ['type' => 'input_text', 'text' => $part['text']];
            } else {
                // Pass through other content types (images, etc.)
                $parts[] = $part;
            }
        }

        return [
            'type' => 'message',
            'role' => 'user',
            'content' => $parts,
        ];
    }

    /**
     * Format a ToolCallMessage into a single function_call item.
     * Note: This is only used when formatting a single message; formatMessages() handles flattening.
     */
    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        // Return first tool call as single item (for formatMessage contract)
        $toolCalls = $message->getToolCalls();
        $first = $toolCalls[0] ?? null;

        if (! $first) {
            return [];
        }

        return [
            'type' => 'function_call',
            'call_id' => $first->getId(),
            'name' => $first->getToolName(),
            'arguments' => $first->getArguments(),
        ];
    }

    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'type' => 'function_call_output',
            'call_id' => $message->getToolCallId(),
            'output' => $message->getContentAsString(),
        ];
    }

    /**
     * Format a single tool for Responses API payload.
     * Uses flat structure (no 'function' wrapper).
     */
    protected function formatTool(ToolInterface $tool): array
    {
        $toolSchema = [
            'type' => 'function',
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ];

        if (! empty($tool->getProperties())) {
            $toolSchema['parameters'] = [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }
}
