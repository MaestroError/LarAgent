<?php

namespace LarAgent\Drivers\Anthropic;

use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\ToolCall;

/**
 * Claude (Anthropic) Message Formatter
 * 
 * This formatter handles conversion between LarAgent message objects and 
 * Claude/Anthropic API format with these key differences from OpenAI:
 * - System messages are sent as a separate 'system' field, not in messages array
 * - Content is always an array of content blocks
 * - Tool calls use 'tool_use' type with 'input_schema' instead of 'parameters'
 * - Tool results use 'tool_result' type with 'tool_use_id'
 * - Usage has 'input_tokens'/'output_tokens' instead of 'prompt_tokens'/'completion_tokens'
 * - Finish reason is 'end_turn' instead of 'stop', 'tool_use' instead of 'tool_calls'
 */
class ClaudeMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to Claude-compatible array format.
     * Note: System messages should be extracted separately using extractSystemInstruction()
     * and not included in the messages array.
     */
    public function formatMessage(MessageInterface $message): array
    {
        // System/Developer messages are handled separately in Claude
        if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
            return [];
        }

        if ($message instanceof ToolResultMessage) {
            return $this->formatToolResultMessage($message);
        }

        if ($message instanceof ToolCallMessage) {
            return $this->formatToolCallMessage($message);
        }

        if ($message instanceof UserMessage) {
            return $this->formatUserMessage($message);
        }

        if ($message instanceof AssistantMessage) {
            return $this->formatAssistantMessage($message);
        }

        // Fallback for unknown message types - content as array
        return [
            'role' => $message->getRole(),
            'content' => [
                ['type' => 'text', 'text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Convert an array of LarAgent messages to Claude-compatible format.
     * Filters out system/developer messages (handled separately).
     */
    public function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $message) {
            // Skip system and developer messages - they're handled by extractSystemInstruction()
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                continue;
            }
            
            $formattedMessage = $this->formatMessage($message);
            if (!empty($formattedMessage)) {
                $formatted[] = $formattedMessage;
            }
        }
        return $formatted;
    }

    /**
     * Convert LarAgent tools to Claude-compatible format.
     * Claude uses 'input_schema' instead of 'parameters'.
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
     * Extract usage/token information from Claude response.
     * Claude uses 'input_tokens'/'output_tokens' instead of 'prompt_tokens'/'completion_tokens'.
     */
    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];
        
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        
        return [
            'prompt_tokens' => $inputTokens,
            'completion_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            // Also include original Claude field names
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * Extract tool calls from Claude response.
     * Claude uses 'tool_use' content blocks with 'id', 'name', 'input'.
     */
    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        
        $content = $response['content'] ?? [];
        
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    $block['id'] ?? '',
                    $block['name'] ?? '',
                    json_encode($block['input'] ?? [])
                );
            }
        }
        
        return $toolCalls;
    }

    /**
     * Extract text content from Claude response.
     * Content is in content[0].text for text blocks.
     */
    public function extractContent(array $response): string
    {
        $content = $response['content'] ?? [];
        
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'] ?? '';
            }
        }
        
        return '';
    }

    /**
     * Extract finish reason from Claude response.
     * Normalizes Claude's values to OpenAI-compatible format.
     */
    public function extractFinishReason(array $response): string
    {
        $reason = $response['stop_reason'] ?? 'end_turn';
        
        // Normalize Claude's stop reasons to OpenAI-compatible format
        return match ($reason) {
            'end_turn' => 'stop',
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            default => $reason,
        };
    }

    /**
     * Check if response contains tool calls.
     */
    public function hasToolCalls(array $response): bool
    {
        $content = $response['content'] ?? [];
        
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                return true;
            }
        }
        
        return false;
    }

    // ========== Claude-specific methods ==========

    /**
     * Extract system instruction from messages.
     * Claude requires system prompt to be sent as a separate 'system' field (string).
     * 
     * @param MessageInterface[] $messages Array of LarAgent message objects
     * @return string|null System prompt string, or null if no system messages
     */
    public function extractSystemInstruction(array $messages): ?string
    {
        $systemInstructions = [];
        
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
                $content = $message->getContentAsString();
                if (!empty($content)) {
                    $systemInstructions[] = $content;
                }
            }
        }
        
        if (empty($systemInstructions)) {
            return null;
        }
        
        return implode("\n", $systemInstructions);
    }

    // ========== Helper methods ==========

    /**
     * Format a UserMessage for Claude.
     * Content must always be an array of content blocks.
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = $message->getContent();
        
        if ($content === null) {
            return [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => '']],
            ];
        }

        // Get content parts as array
        $contentParts = $content->toArray();
        $blocks = [];
        
        foreach ($contentParts as $part) {
            if (isset($part['type'])) {
                switch ($part['type']) {
                    case 'text':
                        $blocks[] = ['type' => 'text', 'text' => $part['text'] ?? ''];
                        break;
                    case 'image_url':
                        // Claude uses 'image' type with 'source'
                        if (isset($part['image_url']['url'])) {
                            $url = $part['image_url']['url'];
                            // Check if it's base64 data
                            if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $matches)) {
                                $blocks[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $matches[1],
                                        'data' => $matches[2],
                                    ],
                                ];
                            } else {
                                // URL image
                                $blocks[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'url',
                                        'url' => $url,
                                    ],
                                ];
                            }
                        }
                        break;
                    default:
                        // Pass through other content types as-is
                        $blocks[] = $part;
                }
            } elseif (isset($part['text'])) {
                $blocks[] = ['type' => 'text', 'text' => $part['text']];
            }
        }
        
        if (empty($blocks)) {
            $blocks[] = ['type' => 'text', 'text' => $message->getContentAsString()];
        }

        return [
            'role' => 'user',
            'content' => $blocks,
        ];
    }

    /**
     * Format an AssistantMessage for Claude.
     * Content must always be an array of content blocks.
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        return [
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Format a ToolCallMessage for Claude.
     * Uses 'tool_use' content blocks.
     */
    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        $content = [];
        
        foreach ($message->getToolCalls() as $toolCall) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall->getId(),
                'name' => $toolCall->getToolName(),
                'input' => json_decode($toolCall->getArguments(), true) ?? [],
            ];
        }
        
        return [
            'role' => 'assistant',
            'content' => $content,
        ];
    }

    /**
     * Format a ToolResultMessage for Claude.
     * Uses 'tool_result' content block with 'tool_use_id'.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->getToolCallId(),
                    'content' => $message->getContentAsString(),
                ],
            ],
        ];
    }

    /**
     * Format a single tool for Claude payload.
     * Claude uses 'input_schema' instead of 'parameters'.
     */
    protected function formatTool(ToolInterface $tool): array
    {
        $toolSchema = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ];

        if (!empty($tool->getProperties())) {
            $toolSchema['input_schema'] = [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        } else {
            // Claude requires input_schema even if empty
            $toolSchema['input_schema'] = [
                'type' => 'object',
                'properties' => (object)[],
                'required' => [],
            ];
        }

        return $toolSchema;
    }
}
