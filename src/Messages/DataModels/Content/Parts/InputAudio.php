<?php

namespace LarAgent\Messages\DataModels\Content\Parts;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

class InputAudio extends DataModel
{
    #[Desc('Base64 encoded audio data')]
    public string $data;

    #[Desc('The format of the audio data (e.g., mp3, wav)')]
    public string $format;

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'format' => $this->format,
        ];
    }

    public static function fromArray(array $attributes): static
    {
        $instance = new static;
        if (isset($attributes['data'])) {
            $instance->data = $attributes['data'];
        }
        if (isset($attributes['format'])) {
            $instance->format = $attributes['format'];
        }

        return $instance;
    }
}
