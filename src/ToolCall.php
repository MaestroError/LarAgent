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

    public function __construct(string $id, string $toolName, string $arguments = '{}')
    {
        $this->id = $id;
        $this->type = 'function';
        $this->function = new ToolCallFunction($toolName, $arguments);
    }

    /**
     * Create a ToolCall instance from an array.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? '';
        $type = $data['type'] ?? 'function';
        $function = ToolCallFunction::fromArray($data['function'] ?? []);

        $instance = new static($id, $function->name, $function->arguments);
        $instance->type = $type;

        return $instance;
    }

    /**
     * Convert the ToolCall to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'function' => $this->function->toArray(),
        ];
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
