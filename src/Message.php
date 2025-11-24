<?php

namespace LarAgent;

use LarAgent\Core\Abstractions\Message as AbstractMessage;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;

// Accessor, Simplified API for messages
class Message extends AbstractMessage
{
    // Create custom message, non-typed
    public static function create(string $role, string|array $content, array $metadata = []): MessageInterface
    {
        self::validateRole($role);

        return new self($role, $content, $metadata);
    }

    /**
     * Create a message from an array
     * !!! Use it with caution, only for internal use, avoiding direct user input
     */
    public static function fromArray(array $data): static
    {
        $msg = new self('', '');

        return $msg->buildFromArray($data);
    }

    public static function fromJSON(string $json): MessageInterface
    {
        $msg = new self('', '');

        return $msg->buildFromJson($json);
    }

    public static function assistant(string $content, array $metadata = []): MessageInterface
    {
        return new AssistantMessage($content, $metadata);
    }

    public static function user(string $content, array $metadata = []): MessageInterface
    {
        return new UserMessage($content, $metadata);
    }

    public static function system(string $content, array $metadata = []): MessageInterface
    {
        return new SystemMessage($content, $metadata);
    }

    /**
     * Create a developer message with specified content and metadata per the 2024-05-08 model spec
     *
     * @link https://cdn.openai.com/spec/model-spec-2024-05-08.html
     *
     * @param  string  $content  The main content of the message.
     * @param  array  $metadata  Additional metadata for the message, defaults to an empty array.
     * @return MessageInterface Returns an instance of DeveloperMessage.
     */
    public static function developer(string $content, array $metadata = []): MessageInterface
    {
        return new DeveloperMessage($content, $metadata);
    }

    public static function toolCall(array $toolCalls, array $message, array $metadata = []): MessageInterface
    {
        return new ToolCallMessage($toolCalls, $message, $metadata);
    }

    public static function toolResult(array $message, array $metadata = []): MessageInterface
    {
        return new ToolResultMessage($message, $metadata);
    }
}
