<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\ToolCall;

class ToolCallArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [ToolCall::class];
    }
}
