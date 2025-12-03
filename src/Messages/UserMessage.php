<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\MessageContent;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\DataModels\Content\AudioContent;

class UserMessage extends Message implements MessageInterface
{
    #[ExcludeFromSchema]
    public string|Role $role = 'user';

    #[Desc('The content of the message as an array of content parts (text, image, audio)')]
    public ?MessageContent $content;

    public function __construct(string|MessageContent $content = '', array $metadata = [])
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
        if ($content !== null && !($content instanceof MessageContent)) {
            throw new \InvalidArgumentException('UserMessage content must be MessageContent or null');
        }
        $this->content = $content;
    }

    public static function fromArray(array $data): static
    {
        $content = $data['content'] ?? '';
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

    public function withImage(string $imageUrl): self
    {
        $imageArray = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $imageUrl,
            ],
        ];

        $this->content[] = ImageContent::fromArray($imageArray);

        return $this;
    }

    /**
     * Add audio to the message
     *
     * @param  string  $format  The format of the audio
     * @param  string  $data  The audio data in Base64
     * @return static
     */
    public function withAudio(string $format, string $data): self
    {
        $audioArray = [
            'type' => 'input_audio',
            'input_audio' => [
                'data' => $data,
                'format' => $format,
            ],
        ];

        $this->content[] = AudioContent::fromArray($audioArray);

        return $this;
    }
}
