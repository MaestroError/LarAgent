<?php

namespace LarAgent;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\DataModels\ToolResultContent;
use LarAgent\Messages\DataModels\MessageContent;
use LarAgent\Messages\DataModels\Content\TextContent;

/**
 * Factory class for creating message instances.
 * Provides a simplified API for message creation.
 */
class Message
{
    public static function assistant(string|TextContent $content, array $metadata = []): AssistantMessage
    {
        return new AssistantMessage($content, $metadata);
    }

    public static function user(string|MessageContent $content, array $metadata = []): UserMessage
    {
        return new UserMessage($content, $metadata);
    }

    public static function system(string|TextContent $content, array $metadata = []): SystemMessage
    {
        return new SystemMessage($content, $metadata);
    }

    /**
     * Create a developer message with specified content and metadata per the 2024-05-08 model spec
     *
     * @link https://cdn.openai.com/spec/model-spec-2024-05-08.html
     *
     * @param  string|TextContent  $content  The main content of the message.
     * @param  array  $metadata  Additional metadata for the message, defaults to an empty array.
     * @return DeveloperMessage Returns an instance of DeveloperMessage.
     */
    public static function developer(string|TextContent $content, array $metadata = []): DeveloperMessage
    {
        return new DeveloperMessage($content, $metadata);
    }

    public static function toolCall(array $toolCalls, array $metadata = []): ToolCallMessage
    {
        return new ToolCallMessage($toolCalls, $metadata);
    }

    public static function toolResult(ToolResultContent|string $content, string $toolCallId = '', string $toolName = '', array $metadata = []): ToolResultMessage
    {
        return new ToolResultMessage($content, $toolCallId, $toolName, $metadata);
    }
}
