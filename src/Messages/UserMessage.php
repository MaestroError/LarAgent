<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;

class UserMessage extends Message implements MessageInterface
{
    public function __construct(string $content, array $metadata = [])
    {
        $this->content = [
            [
                'type' => 'text',
                'text' => $content,
            ],
        ];
        parent::__construct(Role::USER->value, $this->content, $metadata);
    }

    public function withImage(string $imageUrl): self
    {
        $imageArray = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $imageUrl,
            ],
        ];

        $this->content[] = $imageArray;

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

        $this->content[] = $audioArray;

        return $this;
    }
}
