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
        // If type is an array (complex schema like oneOf, object, etc.), merge it directly
        if (is_array($type)) {
            $property = $type;
        } else {
            $property = [
                'type' => $type,
            ];
        }

        if ($description) {
            $property['description'] = $description;
        }
        if ($enum) {
            $property['enum'] = $this->resolveEnum($enum, $name);
        }
        $this->properties[$name] = $property;

        return $this;
    }

    public function setProperties(array $props): self
    {
        $this->properties = $props;

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

    public function setRequiredProps(array $required): self
    {
        $this->required = $required;

        return $this;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getRequired(): array
    {
        return $this->required;
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
        // Get the LlmDriver instance from the container
        $driver = app()->make(\LarAgent\Core\Contracts\LlmDriver::class);

        // Use the driver's format for the tool
        return $driver->formatToolForPayload($this);
    }

    protected function resolveEnum(array $enum, string $name): array
    {
        return $enum;
    }

    abstract public function execute(array $input): mixed;
}
