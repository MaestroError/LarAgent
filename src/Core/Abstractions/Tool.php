<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\Tool as ToolInterface;

abstract class Tool implements ToolInterface
{
    protected string $name;

    protected string $description;

    protected array $properties = [];

    protected array $required = [];

    protected array $metaData = [];

    public function __construct(string $name, string $description, $metaData = [])
    {
        $this->name = $name;
        $this->description = $description;
        $this->metaData = $metaData;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function addProperty(string $name, string|array $type, string $description = '', array $enum = []): self
    {
        $property = [
            'type' => $type,
        ];
        if ($description) {
            $property['description'] = $description;
        }
        if ($enum) {
            $property['enum'] = $this->resolveEnum($enum, $name);
        }
        $this->properties[$name] = $property;

        return $this;
    }

    public function setRequired(string $name): self
    {
        if (! array_key_exists($name, $this->properties)) {
            throw new \InvalidArgumentException("Property '{$name}' does not exist.");
        }

        $this->required[] = $name;

        return $this;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setMetaData(array $metaData): self
    {
        $this->metaData = $metaData;

        return $this;
    }

    public function getMetaData(): array
    {
        return $this->metaData;
    }

    // @todo abstraction
    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $this->getProperties(),
                    'required' => $this->required,
                ],
            ],
        ];
    }

    protected function resolveEnum(array $enum, string $name): array
    {
        return $enum;
    }

    abstract public function execute(array $input): mixed;
}
