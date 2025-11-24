<?php

namespace LarAgent\Messages\DataModels\Content\Parts;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

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
}
