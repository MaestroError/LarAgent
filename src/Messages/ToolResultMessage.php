<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\ToolResultContent;

class ToolResultMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'tool';

    #[Desc('The result content from tool execution')]
    public ?ToolResultContent $content;

    public function __construct(ToolResultContent|string $content, string $toolCallId, string $toolName = '', array $metadata = [])
    {
        parent::__construct();
        
        if ($content instanceof ToolResultContent) {
            $this->content = $content;
        } else {
            $this->content = new ToolResultContent($content, $toolCallId, $toolName);
        }
        
        $this->metadata = $metadata;
    }

    public function getContent(): ?ToolResultContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof ToolResultContent)) {
            throw new \InvalidArgumentException('ToolResultMessage content must be ToolResultContent or null');
        }
        $this->content = $content;
    }

    /**
     * Get the tool call ID this result responds to
     */
    public function getToolCallId(): string
    {
        return $this->content?->tool_call_id ?? '';
    }

    /**
     * Get the tool name
     */
    public function getToolName(): string
    {
        return $this->content?->tool_name ?? '';
    }

    /**
     * Convert to array with OpenAI-compatible format.
     * For ToolResultMessage, content is serialized as plain string with tool_call_id at top level.
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->getRole(),
            'content' => $this->content ? $this->content->toArray() : '',
            'tool_call_id' => $this->getToolCallId(),
            'message_uuid' => $this->message_uuid,
            'message_created' => $this->message_created,
        ];

        if (!empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        return $result;
    }

    public static function fromArray(array $data): static
    {
        $content = $data['content'] ?? '';
        $toolCallId = $data['tool_call_id'];
        $toolName = $data['tool_name'] ?? '';
        $metadata = $data['metadata'] ?? [];
        
        // Handle array content (convert to string)
        if (is_array($content)) {
            $content = json_encode($content);
        }
        
        $instance = new static($content, $toolCallId, $toolName, $metadata);
        
        // Handle message_uuid if provided
        if (isset($data['message_uuid'])) {
            $instance->message_uuid = $data['message_uuid'];
        }

        // Handle message_created if provided
        if (isset($data['message_created'])) {
            $instance->message_created = $data['message_created'];
        }
        
        return $instance;
    }
}
