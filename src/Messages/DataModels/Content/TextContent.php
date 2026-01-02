<?php

namespace LarAgent\Messages\DataModels\Content;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Enums\MessageContentType;

class TextContent extends DataModel
{
    #[Desc('The type of the content')]
    public string $type = MessageContentType::TEXT->value;

    #[Desc('The text content')]
    public string $text;

    public function __construct(string $text = '')
    {
        $this->text = $text;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'text' => $this->text,
        ];
    }

    public static function fromArray(array $attributes): static
    {
        $text = $attributes['text'] ?? '';
        $instance = new static($text);
        if (isset($attributes['type'])) {
            $instance->type = $attributes['type'];
        }

        return $instance;
    }
}
