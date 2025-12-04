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

class DeveloperMessage extends Message implements MessageInterface
{
    use IsUserSent;

    #[ExcludeFromSchema]
    public string|Role $role = Role::DEVELOPER;

    #[Desc('The developer instruction content')]
    public ?MessageContent $content;

}
