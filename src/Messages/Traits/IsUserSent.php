<?php

namespace LarAgent\Messages\Traits;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Messages\DataModels\MessageContent;

trait IsUserSent
{
    public function __construct(string|MessageContent $content, array $metadata = [])
    {
        parent::__construct();

        if (is_string($content)) {
            $this->content = new MessageContent([new TextContent($content)]);
        } else {
            $this->content = $content;
        }

        $this->metadata = $metadata;
    }

    public function getContent(): ?MessageContent
    {
        return $this->content;
    }

    public function setContent(?DataModelContract $content): void
    {
        if ($content !== null && ! ($content instanceof MessageContent)) {
            throw new \InvalidArgumentException('SystemMessage content must be TextContent or null');
        }
        $this->content = $content;
    }

    /**
     * Convert to array with OpenAI-compatible content format.
     * For SystemMessage, content is serialized as plain string.
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->getRole(),
            'content' => $this->content ? $this->content->toArray() : null,
            'message_uuid' => $this->message_uuid,
            'message_created' => $this->message_created,
        ];

        if (! empty($this->extras)) {
            $result['extras'] = $this->extras;
        }

        return $result;
    }

    public static function fromArray(array $data): static
    {
        $content = $data['content'];
        $metadata = $data['metadata'] ?? [];

        if (is_array($content)) {
            $instance = new static('');
            $instance->content = new MessageContent($content);
            $instance->metadata = $metadata;

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

        $instance = new static($content, $metadata);

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
