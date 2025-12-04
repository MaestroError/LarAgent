<?php

namespace LarAgent\Drivers\Gemini;

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
 * Gemini Message Formatter
 * 
 * This formatter handles conversion between LarAgent message objects and 
 * Gemini-native API format with these key differences from OpenAI:
 * - Uses 'model' role instead of 'assistant'
 * - Content is in 'parts' array format
 * - System instructions are separate from messages
 * - Tool calls use 'functionCall' / 'functionResponse' format
 */
class GeminiMessageFormatter implements MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to Gemini-compatible array format.
     * Note: System and Developer messages should be extracted separately
     * using extractSystemInstruction() and not included in the messages array.
     */
    public function formatMessage(MessageInterface $message): array
    {
        // System/Developer messages are handled separately in Gemini
        if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
            // Return empty - these are extracted via extractSystemInstruction()
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

        // Fallback for unknown message types
        return [
            'role' => $this->mapRoleToGeminiRole($message->getRole()),
            'parts' => [
                ['text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Convert an array of LarAgent messages to Gemini-compatible format.
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
     * Convert LarAgent tools to Gemini-compatible format.
     * Gemini uses 'functionDeclarations' array.
     */
    public function formatTools(array $tools): array
    {
        $functionDeclarations = [];
        foreach ($tools as $tool) {
            $functionDeclarations[] = $this->formatTool($tool);
        }
        
        // Gemini expects tools wrapped in an array with functionDeclarations
        return [
            [
                'functionDeclarations' => $functionDeclarations,
            ],
        ];
    }

    // ========== Driver → LarAgent (extracting from API response) ==========

    /**
     * Extract usage/token information from Gemini response.
     * Gemini uses 'usageMetadata' with different field names.
     * Normalizes to standard keys: prompt_tokens, completion_tokens, total_tokens.
     */
    public function extractUsage(array $response): array
    {
        if (!isset($response['usageMetadata'])) {
            return [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];
        }

        $meta = $response['usageMetadata'];
        $promptTokens = $meta['promptTokenCount'] ?? 0;
        $completionTokens = $meta['candidatesTokenCount'] ?? 0;

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $meta['totalTokenCount'] ?? ($promptTokens + $completionTokens),
        ];
    }

    /**
     * Extract tool calls from Gemini response.
     * Gemini uses 'functionCall' in parts array.
     */
    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    $toolCalls[] = new ToolCall(
                        'tool_call_' . uniqid(), // Gemini doesn't provide IDs
                        $part['functionCall']['name'] ?? '',
                        json_encode($part['functionCall']['args'] ?? [])
                    );
                }
            }
        }

        return $toolCalls;
    }

    /**
     * Extract text content from Gemini response.
     * Content is in parts[0].text format.
     */
    public function extractContent(array $response): string
    {
        return $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Extract finish reason from Gemini response.
     * Gemini uses uppercase values like 'STOP', normalize to lowercase.
     */
    public function extractFinishReason(array $response): string
    {
        $reason = $response['candidates'][0]['finishReason'] ?? 'STOP';
        
        // Normalize Gemini's finish reasons to OpenAI-compatible format
        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            'RECITATION' => 'content_filter',
            // Gemini uses 'STOP' even for tool calls, so we detect by content
            default => strtolower($reason),
        };
    }

    /**
     * Check if response contains tool calls.
     * Useful since Gemini might return 'STOP' even with tool calls.
     */
    public function hasToolCalls(array $response): bool
    {
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    return true;
                }
            }
        }
        return false;
    }

    // ========== Gemini-specific methods ==========

    /**
     * Extract system instructions from messages.
     * Gemini requires system instructions to be sent separately in 'systemInstruction' field.
     * 
     * @param MessageInterface[] $messages Array of LarAgent message objects
     * @return array|null Gemini systemInstruction format, or null if no system messages
     */
    public function extractSystemInstruction(array $messages): ?array
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
        
        $instructionText = implode("\n", $systemInstructions);
        
        return [
            'parts' => [
                ['text' => $instructionText],
            ],
        ];
    }

    // ========== Helper methods ==========

    /**
     * Map LarAgent roles to Gemini roles.
     */
    protected function mapRoleToGeminiRole(string $role): string
    {
        return match ($role) {
            'user' => 'user',
            'assistant' => 'model',
            'tool' => 'user', // Tool results are sent as user messages in Gemini
            default => 'user',
        };
    }

    /**
     * Format a UserMessage for Gemini.
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = $message->getContent();
        
        if ($content === null) {
            return [
                'role' => 'user',
                'parts' => [['text' => '']],
            ];
        }

        // Get content parts as array
        $contentParts = $content->toArray();
        $parts = [];
        
        foreach ($contentParts as $part) {
            if (isset($part['type'])) {
                switch ($part['type']) {
                    case 'text':
                        $parts[] = ['text' => $part['text'] ?? ''];
                        break;
                    case 'image_url':
                        // Gemini uses inline_data for images
                        // Note: URL images might need conversion to base64
                        if (isset($part['image_url']['url'])) {
                            $url = $part['image_url']['url'];
                            // Check if it's base64 data
                            if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $matches)) {
                                $parts[] = [
                                    'inline_data' => [
                                        'mime_type' => $matches[1],
                                        'data' => $matches[2],
                                    ],
                                ];
                            } else {
                                // For URL images, Gemini might need file_data or conversion
                                // This is a simplified version
                                $parts[] = ['text' => "[Image: {$url}]"];
                            }
                        }
                        break;
                }
            } elseif (isset($part['text'])) {
                $parts[] = ['text' => $part['text']];
            }
        }
        
        if (empty($parts)) {
            $parts[] = ['text' => $message->getContentAsString()];
        }

        return [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    /**
     * Format an AssistantMessage for Gemini.
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        return [
            'role' => 'model',
            'parts' => [
                ['text' => $message->getContentAsString()],
            ],
        ];
    }

    /**
     * Format a ToolCallMessage for Gemini.
     * Uses 'functionCall' format in parts.
     */
    protected function formatToolCallMessage(ToolCallMessage $message): array
    {
        $parts = [];
        
        foreach ($message->getToolCalls() as $toolCall) {
            $parts[] = [
                'functionCall' => [
                    'name' => $toolCall->getToolName(),
                    'args' => json_decode($toolCall->getArguments(), true) ?? [],
                ],
            ];
        }
        
        return [
            'role' => 'model',
            'parts' => $parts,
        ];
    }

    /**
     * Format a ToolResultMessage for Gemini.
     * Uses 'functionResponse' format, sent as user message.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $toolName = $message->getToolName();
        $responseContent = $message->getContentAsString();
        
        return [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => [
                            'name' => $toolName,
                            'content' => $responseContent,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Format a single tool for Gemini payload.
     * Gemini uses 'functionDeclaration' format (without the 'type' wrapper).
     */
    protected function formatTool(ToolInterface $tool): array
    {
        $toolSchema = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ];

        if (!empty($tool->getProperties())) {
            $toolSchema['parameters'] = [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }
}
