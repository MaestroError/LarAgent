<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;

class MessageArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'user' => UserMessage::class,
            'system' => SystemMessage::class,
            'developer' => DeveloperMessage::class,
            'tool' => ToolResultMessage::class,
            'assistant' => [
                ToolCallMessage::class,  // Check first (has specific condition via matchesArray)
                AssistantMessage::class, // Default fallback
            ],
        ];
    }

    public function discriminator(): string
    {
        return 'role';
    }
}
