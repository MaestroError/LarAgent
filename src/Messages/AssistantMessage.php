<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Messages\DataModels\Usage;

class AssistantMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'assistant';

    #[Desc('The text content of the assistant response')]
    public ?TextContent $content;

    /**
     * Token usage information from the API response.
     * Excluded from schema as it's not sent to the LLM API.
     */
    #[ExcludeFromSchema]
    public ?Usage $usage = null;

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
            throw new \InvalidArgumentException('AssistantMessage content must be TextContent or null');
        }
        $this->content = $content;
    }

    /**
     * Get the usage information for this message.
     */
    public function getUsage(): ?Usage
    {
        return $this->usage;
    }

    /**
     * Set the usage information for this message.
     * Accepts either a Usage DataModel or an array (for backward compatibility).
     * 
     * @param Usage|array|null $usage
     * @return $this
     */
    public function setUsage(Usage|array|null $usage): static
    {
        if ($usage === null) {
            $this->usage = null;
        } elseif ($usage instanceof Usage) {
            $this->usage = $usage;
        } else {
            // Convert array to Usage DataModel
            $this->usage = Usage::fromArray($usage);
        }

        return $this;
    }

    /**
     * Check if array data matches AssistantMessage (no tool_calls).
     */
    public static function matchesArray(array $data): bool
    {
        return empty($data['tool_calls']);
    }

    /**
     * Convert to array with OpenAI-compatible content format.
     * For AssistantMessage, content is serialized as plain string.
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->getRole(),
            'content' => $this->content ? (string) $this->content : null,
            'id' => $this->id,
        ];

        if (!empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        // Include usage for storage purposes (not sent to API)
        if ($this->usage !== null) {
            $result['usage'] = $this->usage->toArray();
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
        
        // Handle id if provided
        if (isset($data['id'])) {
            $instance->id = $data['id'];
        }
        
        // Reconstruct usage from array data
        if (isset($data['usage']) && is_array($data['usage'])) {
            $instance->usage = Usage::fromArray($data['usage']);
        }
        
        return $instance;
    }
}
