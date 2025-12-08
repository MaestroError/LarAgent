<?php

namespace LarAgent;

use LarAgent\Core\Abstractions\Tool as AbstractTool;
use LarAgent\Core\Contracts\Tool as ToolInterface;

class Tool extends AbstractTool implements ToolInterface
{
    protected mixed $callback = null;

    protected array $enumTypes = [];

    protected array $dataModelTypes = [];

    public function __construct(?string $name = null, ?string $description = null)
    {
        $this->name = $name ?? $this->name;
        $this->description = $description ?? $this->description;
        parent::__construct($this->name, $this->description);
    }

    public function addDataModelType(string $paramName, string $dataModelClass): self
    {
        $this->dataModelTypes[$paramName] = $dataModelClass;

        return $this;
    }

    public function setCallback(?callable $callback): Tool
    {
        $this->callback = $callback;

        return $this;
    }

    public function getCallback(): ?callable
    {
        return $this->callback;
    }

    public function execute(array $input): mixed
    {
        if ($this->callback === null) {
            throw new \BadMethodCallException('No callback defined for execution.');
        }

        // Validate required parameters
        foreach ($this->required as $param) {
            if (! array_key_exists($param, $input)) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
        }

        // Convert enum string values to actual enum instances and DataModel arrays to instances
        $convertedInput = $this->convertSpecialTypes($input);

        // Execute the callback with input
        return call_user_func($this->callback, ...$convertedInput);
    }

    public static function create(string $name, string $description): Tool
    {
        return new self($name, $description);
    }

    protected function convertSpecialTypes(array $input): array
    {
        // Convert enums
        foreach ($this->enumTypes as $paramName => $enumClass) {
            if (isset($input[$paramName])) {
                $input[$paramName] = $enumClass::from($input[$paramName]);
            }
        }

        // Convert DataModels
        foreach ($this->dataModelTypes as $paramName => $dataModelClass) {
            if (isset($input[$paramName]) && is_array($input[$paramName])) {
                $input[$paramName] = $dataModelClass::fromArray($input[$paramName]);
            }
        }

        return $input;
    }

    protected function convertEnumValues(array $input): array
    {
        foreach ($this->enumTypes as $paramName => $enumClass) {
            if (isset($input[$paramName])) {
                $input[$paramName] = $enumClass::from($input[$paramName]);
            }
        }

        return $input;
    }

    protected function resolveEnum(array $enum, string $name): array
    {
        // Store the enum class if it's an enum type
        if (isset($enum['enumClass'])) {
            $this->enumTypes[$name] = $enum['enumClass'];

            return $enum['values'];
        }

        return $enum;
    }
}
