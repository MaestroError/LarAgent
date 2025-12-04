<?php

namespace LarAgent\Messages\DataModels\Content;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Enums\MessageContentType;
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

    public static function fromArray(array $attributes): static
    {
        $instance = new static;
        if (isset($attributes['type'])) {
            $instance->type = $attributes['type'];
        }
        if (isset($attributes['image_url'])) {
            $instance->image_url = is_array($attributes['image_url'])
                ? ImageUrl::fromArray($attributes['image_url'])
                : $attributes['image_url'];
        }

        return $instance;
    }
}
