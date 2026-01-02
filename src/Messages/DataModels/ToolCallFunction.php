<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

class ToolCallFunction extends DataModel
{
    #[Desc('The name of the function to call')]
    public string $name;

    #[Desc('The arguments to pass to the function as JSON string')]
    public string $arguments;

    public function __construct(string $name, string $arguments = '{}')
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }

    /**
     * Create a ToolCallFunction instance from an array.
     */
    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        $arguments = $data['arguments'] ?? '{}';

        return new static($name, $arguments);
    }

    /**
     * Convert the ToolCallFunction to an array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
