<?php

namespace LarAgent\Messages\DataModels\Content\Parts;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

class ImageUrl extends DataModel
{
    #[Desc('The URL of the image')]
    public string $url;

    #[Desc('The detail level of the image (auto, low, high)')]
    public ?string $detail = null;

    public function toArray(): array
    {
        $data = ['url' => $this->url];

        if ($this->detail) {
            $data['detail'] = $this->detail;
        }

        return $data;
    }

    public static function fromArray(array $attributes): static
    {
        $instance = new static;
        if (isset($attributes['url'])) {
            $instance->url = $attributes['url'];
        }
        if (isset($attributes['detail'])) {
            $instance->detail = $attributes['detail'];
        }

        return $instance;
    }
}
