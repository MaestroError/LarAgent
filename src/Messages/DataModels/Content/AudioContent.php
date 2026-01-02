<?php

namespace LarAgent\Messages\DataModels\Content;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Enums\MessageContentType;
use LarAgent\Messages\DataModels\Content\Parts\InputAudio;

class AudioContent extends DataModel
{
    #[Desc('The type of the content')]
    public string $type = MessageContentType::INPUT_AUDIO->value;

    #[Desc('The input audio information')]
    public InputAudio $input_audio;

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'input_audio' => $this->input_audio->toArray(),
        ];
    }

    public static function fromArray(array $attributes): static
    {
        $instance = new static;
        if (isset($attributes['type'])) {
            $instance->type = $attributes['type'];
        }
        if (isset($attributes['input_audio'])) {
            $instance->input_audio = is_array($attributes['input_audio'])
                ? InputAudio::fromArray($attributes['input_audio'])
                : $attributes['input_audio'];
        }

        return $instance;
    }
}
