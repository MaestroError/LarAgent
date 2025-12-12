<?php

namespace LarAgent\BuiltIn\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

/**
 * Represents a single message symbol/summary for truncation.
 */
class MessageSymbol extends DataModel
{
    /**
     * The role of the message (user, assistant, etc.)
     */
    #[Desc('The role of the original message (User or You)')]
    public string $role;

    /**
     * A brief 1-sentence symbol/summary of the message content
     */
    #[Desc('A brief 1-sentence symbol/summary of the message content (max 15 words)')]
    public string $symbol;
}
