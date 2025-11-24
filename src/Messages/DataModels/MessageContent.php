<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\DataModels\Content\AudioContent;
use LarAgent\Core\Enums\MessageContentType;

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
}
