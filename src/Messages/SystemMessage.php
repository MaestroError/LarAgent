<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Enums\Role;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\MessageContent;
use LarAgent\Messages\Traits\IsUserSent;

class SystemMessage extends Message implements MessageInterface
{
    use IsUserSent;

    #[ExcludeFromSchema]
    public string|Role $role = Role::SYSTEM;

    #[Desc('The system instruction content')]
    public ?MessageContent $content;
}
