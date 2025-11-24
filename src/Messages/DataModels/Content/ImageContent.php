<?php

namespace LarAgent\Messages\DataModels\Content;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Enums\MessageContentType;
use LarAgent\Attributes\Desc;

use LarAgent\Messages\DataModels\Content\Parts\ImageUrl;

class ImageContent extends DataModel
{
    #[Desc('The type of the content')]
    public string $type = MessageContentType::IMAGE_URL->value;

    #[Desc('The image URL information')]
    public ImageUrl $image_url;

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'image_url' => $this->image_url->toArray(),
        ];
    }
}
