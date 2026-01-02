<?php

namespace LarAgent\Messages;

use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;
use LarAgent\Messages\DataModels\Content\AudioContent;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\DataModels\MessageContent;
use LarAgent\Messages\Traits\IsUserSent;

class UserMessage extends Message implements MessageInterface
{
    use IsUserSent;

    #[ExcludeFromSchema]
    public string|Role $role = Role::USER;

    #[Desc('The content of the message as an array of content parts (text, image, audio)')]
    public ?MessageContent $content;

    public function withImage(string $imageUrl): self
    {
        $imageArray = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $imageUrl,
            ],
        ];

        $this->content->add(ImageContent::fromArray($imageArray));

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

        $this->content->add(AudioContent::fromArray($audioArray));

        return $this;
    }
}
