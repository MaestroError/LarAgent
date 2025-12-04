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

    public function __construct(string $name, string $arguments = '{}')
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }

    /**
     * Create a ToolCallFunction instance from an array.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        $arguments = $data['arguments'] ?? '{}';

        return new static($name, $arguments);
    }

    /**
     * Convert the ToolCallFunction to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
