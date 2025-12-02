<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\Content\TextContent;

class DeveloperMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'developer';

    #[Desc('The developer instruction content')]
    public ?TextContent $content;

    public function __construct(string|TextContent $content = '', array $metadata = [])
    {
        parent::__construct();
        
        if (is_string($content)) {
            $this->content = new TextContent($content);
        } else {
            $this->content = $content;
        }
        
        $this->metadata = $metadata;
    }

    public function getContent(): ?TextContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && !($content instanceof TextContent)) {
            throw new \InvalidArgumentException('DeveloperMessage content must be TextContent or null');
        }
        $this->content = $content;
    }

    /**
     * Convert to array with OpenAI-compatible content format.
     * For DeveloperMessage, content is serialized as plain string.
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->getRole(),
            'content' => $this->content ? (string) $this->content : null,
            'message_uuid' => $this->message_uuid,
        ];

        if (!empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        return $result;
    }

    public static function fromArray(array $data): static
    {
        $content = $data['content'] ?? '';
        
        // Handle array content (from API responses or serialization)
        if (is_array($content)) {
            // Check if it's a single TextContent object format: {'type': 'text', 'text': '...'}
            if (isset($content['type']) && isset($content['text'])) {
                $content = $content['text'];
            } else {
                // Array of parts format: [{'type': 'text', 'text': '...'}, ...]
                $textParts = [];
                foreach ($content as $part) {
                    if (isset($part['text'])) {
                        $textParts[] = $part['text'];
                    }
                }
                $content = implode("\n", $textParts);
            }
        }
        
        $metadata = $data['metadata'] ?? [];
        
        $instance = new static($content, $metadata);
        
        // Handle message_uuid if provided
        if (isset($data['message_uuid'])) {
            $instance->message_uuid = $data['message_uuid'];
        }
        
        return $instance;
    }
}
