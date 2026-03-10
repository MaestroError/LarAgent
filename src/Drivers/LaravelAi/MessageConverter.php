<?php

namespace LarAgent\Drivers\LaravelAi;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\Usage\DataModels\Usage;

class MessageConverter
{
    /**
     * Extract system/developer instructions from the message array.
     * Returns the instruction string and the remaining messages without mutating the input.
     *
     * @param  array<MessageInterface>  $messages
     * @return array{0: ?string, 1: array<MessageInterface>} [$instructions, $remainingMessages]
     */
    public static function extractInstructions(array $messages): array
    {
        if (empty($messages)) {
            return [null, $messages];
        }

        $first = $messages[0];
        $role = $first->getRole();

        if ($role === 'system' || $role === 'developer' || $role === Role::SYSTEM->value) {
            return [$first->getContentAsString(), array_slice($messages, 1)];
        }

        return [null, $messages];
    }

    /**
     * Convert LarAgent messages to Laravel AI SDK Message objects.
     *
     * @param  array<MessageInterface>  $messages
     * @return array<\Laravel\Ai\Messages\Message>
     */
    public static function toLaravelAiMessages(array $messages): array
    {
        if (! class_exists(\Laravel\Ai\Messages\Message::class)) {
            throw new \RuntimeException('Laravel AI SDK is not installed. Install laravel/ai package.');
        }

        $sdkMessages = [];

        foreach ($messages as $message) {
            $converted = static::convertMessage($message);
            if ($converted !== null) {
                $sdkMessages[] = $converted;
            }
        }

        return $sdkMessages;
    }

    /**
     * Convert a single LarAgent message to an SDK Message.
     * Multimodal content (images, audio) is detected but currently reduced to
     * text-only for SDK compatibility; image URLs are appended as references.
     */
    protected static function convertMessage(MessageInterface $message): ?\Laravel\Ai\Messages\Message
    {
        $role = $message->getRole();

        // ToolCallMessage and ToolResultMessage are skipped for SDK conversion
        // because the SDK manages its own tool loop. We only send user/assistant context.
        if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
            return null;
        }

        if ($role === 'user' || $role === Role::USER->value) {
            $content = static::extractUserContent($message);

            return new \Laravel\Ai\Messages\Message('user', $content);
        }

        if ($role === 'assistant' || $role === Role::ASSISTANT->value) {
            return new \Laravel\Ai\Messages\Message('assistant', $message->getContentAsString());
        }

        // System messages should have been extracted already
        return null;
    }

    /**
     * Extract content from a user message, handling multimodal content.
     * If the message contains images, their URLs are appended as text references
     * since the SDK message format may not support inline multimodal content.
     */
    protected static function extractUserContent(MessageInterface $message): string
    {
        $textContent = $message->getContentAsString();

        // Check for multimodal content in UserMessage instances
        if ($message instanceof UserMessage && $message->content !== null) {
            $imageUrls = [];
            foreach ($message->content->all() as $part) {
                if ($part instanceof ImageContent) {
                    $url = $part->image_url->url ?? null;
                    if ($url !== null) {
                        $imageUrls[] = $url;
                    }
                }
            }

            // Append image URLs as text references
            if (! empty($imageUrls)) {
                $refs = implode("\n", array_map(fn ($url) => "[Image: {$url}]", $imageUrls));
                $textContent = trim($textContent."\n\n".$refs);
            }
        }

        return $textContent;
    }

    /**
     * Convert an SDK AgentResponse to a LarAgent AssistantMessage.
     * Usage is aggregated across all steps + final response so that
     * truncation sees the full cumulative token cost.
     *
     * @param  object  $response  SDK AgentResponse instance
     */
    public static function fromSdkResponse(object $response): AssistantMessage
    {
        $content = '';
        if (isset($response->text) && is_string($response->text)) {
            $content = $response->text;
        }

        $message = new AssistantMessage($content);

        // Aggregate usage across all SDK steps + final response
        // This gives truncation accurate cumulative token data
        $aggregatedUsage = static::aggregateStepUsage($response);
        if ($aggregatedUsage !== null) {
            $message->setUsage($aggregatedUsage);
        } elseif (isset($response->usage)) {
            // Fallback: use only the final response usage if no steps
            $usage = static::convertUsage($response->usage);
            if (! empty($usage)) {
                $message->setUsage(Usage::fromArray($usage));
            }
        }

        return $message;
    }

    /**
     * Extract intermediate tool call/result messages from SDK response.
     * These are the messages generated during the SDK's internal tool loop.
     * Each step's usage data is attached to the ToolResultMessage as metadata
     * so truncation and usage tracking can account for intermediate token costs.
     *
     * @param  object  $response  SDK AgentResponse instance
     * @return array<MessageInterface>
     */
    public static function extractIntermediateMessages(object $response): array
    {
        $intermediateMessages = [];

        // The SDK response may include steps with tool invocations
        if (! isset($response->steps) || empty($response->steps)) {
            return $intermediateMessages;
        }

        foreach ($response->steps as $step) {
            // Each step represents a tool invocation cycle
            if (isset($step->toolName) && isset($step->toolArgs)) {
                $toolCallId = 'sdk_tc_'.bin2hex(random_bytes(8));

                // Normalize arguments to valid JSON string
                $rawArgs = is_string($step->toolArgs) ? $step->toolArgs : json_encode($step->toolArgs);
                if (json_decode($rawArgs) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $rawArgs = json_encode(['value' => $step->toolArgs]);
                }

                $toolCall = new \LarAgent\ToolCall($toolCallId, $step->toolName, $rawArgs);
                $toolCallMessage = new ToolCallMessage([$toolCall]);
                $intermediateMessages[] = $toolCallMessage;

                // Create a ToolResultMessage for history
                $result = $step->toolResult ?? '';
                $resultContent = is_string($result) ? $result : json_encode($result);
                $toolResultMessage = new ToolResultMessage($resultContent, $toolCallId, $step->toolName);

                // Attach per-step usage as metadata for observability
                if (isset($step->usage)) {
                    $stepUsage = static::convertUsage($step->usage);
                    if (! empty($stepUsage)) {
                        $toolResultMessage->setExtra('step_usage', $stepUsage);
                    }
                }

                $intermediateMessages[] = $toolResultMessage;
            }
        }

        return $intermediateMessages;
    }

    /**
     * Aggregate token usage from all SDK response steps plus the final response usage.
     * Returns a Usage object with cumulative totals that represents the full cost
     * of the SDK's multi-step tool loop.
     *
     * @param  object  $response  SDK AgentResponse instance
     * @return Usage|null Aggregated usage, or null if no usage data available
     */
    public static function aggregateStepUsage(object $response): ?Usage
    {
        $totalPrompt = 0;
        $totalCompletion = 0;
        $hasUsage = false;

        // Sum usage from each step
        if (isset($response->steps) && is_array($response->steps)) {
            foreach ($response->steps as $step) {
                if (isset($step->usage)) {
                    $stepUsage = static::convertUsage($step->usage);
                    if (! empty($stepUsage)) {
                        $totalPrompt += $stepUsage['prompt_tokens'];
                        $totalCompletion += $stepUsage['completion_tokens'];
                        $hasUsage = true;
                    }
                }
            }
        }

        // Add the final response usage
        if (isset($response->usage)) {
            $finalUsage = static::convertUsage($response->usage);
            if (! empty($finalUsage)) {
                $totalPrompt += $finalUsage['prompt_tokens'];
                $totalCompletion += $finalUsage['completion_tokens'];
                $hasUsage = true;
            }
        }

        if (! $hasUsage) {
            return null;
        }

        return new Usage($totalPrompt, $totalCompletion);
    }

    /**
     * Convert SDK usage object to LarAgent usage array format.
     *
     * @param  mixed  $usage  SDK usage object or array
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public static function convertUsage(mixed $usage): array
    {
        if ($usage === null) {
            return [];
        }

        // Handle object with properties
        if (is_object($usage)) {
            $promptTokens = $usage->promptTokens ?? $usage->prompt_tokens ?? 0;
            $completionTokens = $usage->completionTokens ?? $usage->completion_tokens ?? 0;

            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ];
        }

        // Handle array format
        if (is_array($usage)) {
            $promptTokens = $usage['promptTokens'] ?? $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completionTokens'] ?? $usage['completion_tokens'] ?? 0;

            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ];
        }

        return [];
    }

    /**
     * Extract the last user message content from the messages array.
     */
    public static function extractLastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $role = $messages[$i]->getRole();
            if ($role === 'user' || $role === Role::USER->value) {
                return $messages[$i]->getContentAsString();
            }
        }

        return '';
    }
}
