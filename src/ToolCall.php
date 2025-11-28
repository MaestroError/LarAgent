<?php

namespace LarAgent;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\DataModels\ToolCallFunction;
use LarAgent\Attributes\Desc;

class ToolCall extends DataModel implements ToolCallInterface
{
    #[Desc('Unique identifier for the tool call')]
    public string $id;

    #[Desc('Type of tool call, always "function"')]
    public string $type = 'function';

    #[Desc('Function details')]
    public ToolCallFunction $function;

    public function __construct(string $id = '', string $toolName = '', string $arguments = '{}')
    {
        $this->id = $id;
        $this->type = 'function';
        $this->function = new ToolCallFunction($toolName, $arguments);
    }

    // ToolCallInterface methods
    public function getId(): string
    {
        return $this->id;
    }

    public function getToolName(): string
    {
        return $this->function->name;
    }

    public function getArguments(): string
    {
        return $this->function->arguments;
    }
}
