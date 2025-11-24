<?php

namespace LarAgent\Messages\DataModels\Content;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Enums\MessageContentType;
use LarAgent\Attributes\Desc;

class TextContent extends DataModel
{
    #[Desc('The type of the content')]
    public string $type = MessageContentType::TEXT->value;

    #[Desc('The text content')]
    public string $text;

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'text' => $this->text,
        ];
    }
}
