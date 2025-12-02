<?php

namespace LarAgent\Messages;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\ToolCallArray;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\ToolCall;

class ToolCallMessage extends AssistantMessage implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'assistant';

    // Content is null for tool call messages (inherited from AssistantMessage)
    #[ExcludeFromSchema]
    public ?TextContent $content = null;

    #[Desc('Array of tool calls requested by the assistant')]
    public ToolCallArray $toolCalls;

    public function __construct(ToolCallArray|array $toolCalls = [], array $metadata = [])
    {
        parent::__construct('', $metadata);
        $this->content = null; // ToolCallMessage has no text content
        
        if ($toolCalls instanceof ToolCallArray) {
            $this->toolCalls = $toolCalls;
        } else {
            $this->toolCalls = new ToolCallArray($toolCalls);
        }
    }

    /**
     * Check if array data matches ToolCallMessage (has tool_calls).
     */
    public static function matchesArray(array $data): bool
    {
        return !empty($data['tool_calls']);
    }

    public static function fromArray(array $data): static
    {
        $toolCalls = $data['tool_calls'] ?? [];
        $metadata = $data['metadata'] ?? [];

        $instance = new static($toolCalls, $metadata);
        
        // Handle message_uuid if provided
        if (isset($data['message_uuid'])) {
            $instance->message_uuid = $data['message_uuid'];
        }
        
        // Handle any extras
        $knownKeys = ['role', 'tool_calls', 'metadata', 'message_uuid', 'extras', 'content'];
        foreach ($data as $key => $value) {
            if (!in_array($key, $knownKeys)) {
                $instance->extras[$key] = $value;
            }
        }
        
        if (isset($data['extras'])) {
            $instance->extras = array_merge($instance->extras, $data['extras']);
        }

        return $instance;
    }

    public function getToolCalls(): ToolCallArray
    {
        return $this->toolCalls;
    }

    /**
     * Convert to array with OpenAI-compatible format.
     * For ToolCallMessage, includes tool_calls and null content.
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->getRole(),
            'content' => null,
            'tool_calls' => $this->toolCalls->toArray(),
            'message_uuid' => $this->message_uuid,
        ];

        if (!empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        return $result;
    }
}
