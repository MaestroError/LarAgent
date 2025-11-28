<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ToolCallFunction extends DataModel
{
    #[Desc('The name of the function to call')]
    public string $name;

    #[Desc('The arguments to pass to the function as JSON string')]
    public string $arguments;

    public function __construct(string $name = '', string $arguments = '{}')
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
