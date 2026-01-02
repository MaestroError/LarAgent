<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;

interface MessageFormatter
{
    // ========== LarAgent → Driver (formatting for API request) ==========

    /**
     * Convert a single LarAgent message to driver-specific array format.
     * Handles all message types: User, Assistant, System, Developer, ToolCall, ToolResult.
     *
     * @param  MessageInterface  $message  Any LarAgent message object
     * @return array Driver-specific message array
     */
    public function formatMessage(MessageInterface $message): array;

    /**
     * Convert an array of LarAgent messages to driver-specific format.
     * Simply iterates and calls formatMessage() for each.
     * May skip certain message types (e.g., system messages handled separately).
     *
     * @param  MessageInterface[]  $messages  Array of LarAgent message objects
     * @return array Array of driver-specific message arrays
     */
    public function formatMessages(array $messages): array;

    /**
     * Convert LarAgent tools to driver-specific format.
     *
     * @param  ToolInterface[]  $tools  Array of LarAgent tool objects
     * @return array Driver-specific tools array (for payload)
     */
    public function formatTools(array $tools): array;

    // ========== Driver → LarAgent (extracting from API response) ==========

    /**
     * Extract usage/token information from driver response.
     *
     * @param  array  $response  Raw API response array
     * @return array Normalized usage array ['prompt_tokens' => int, 'completion_tokens' => int, 'total_tokens' => int]
     */
    public function extractUsage(array $response): array;

    /**
     * Extract tool calls from driver response.
     * Returns array of ToolCall objects (LarAgent format).
     *
     * @param  array  $response  Raw API response array
     * @return \LarAgent\ToolCall[] Array of LarAgent ToolCall objects
     */
    public function extractToolCalls(array $response): array;

    /**
     * Extract text content from driver response.
     *
     * @param  array  $response  Raw API response array
     * @return string The text content from the response
     */
    public function extractContent(array $response): string;

    /**
     * Extract finish reason from driver response.
     * Returns normalized values: 'stop', 'tool_calls', 'length', 'content_filter'
     *
     * @param  array  $response  Raw API response array
     * @return string Normalized finish reason
     */
    public function extractFinishReason(array $response): string;
}
