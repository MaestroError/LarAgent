<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Core\Enums\MessageContentType;
use LarAgent\Messages\DataModels\Content\AudioContent;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\DataModels\Content\TextContent;

class MessageContent extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            MessageContentType::TEXT->value => TextContent::class,
            MessageContentType::IMAGE_URL->value => ImageContent::class,
            MessageContentType::INPUT_AUDIO->value => AudioContent::class,
        ];
    }

    public function discriminator(): string
    {
        return 'type';
    }

    /**
     * Convert MessageContent to string by extracting text from TextContent items.
     */
    public function __toString(): string
    {
        $texts = [];
        foreach ($this->items as $item) {
            if ($item instanceof TextContent) {
                $texts[] = (string) $item;
            }
        }

        return implode("\n", $texts);
    }
}
