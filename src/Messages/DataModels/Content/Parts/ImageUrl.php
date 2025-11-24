<?php

namespace LarAgent\Messages\DataModels\Content\Parts;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

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
}
