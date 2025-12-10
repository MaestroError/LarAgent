<?php

namespace LarAgent\Usage\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class UsageArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            Usage::class,
        ];
    }

    public function discriminator(): string
    {
        return 'type';
    }
}
