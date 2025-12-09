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

    public function addDataModelType(string $paramName, string|array $dataModelClass): self
    {
        $this->dataModelTypes[$paramName] = $dataModelClass;

        return $this;
    }

    public function addEnumType(string $paramName, string|array $enumClass): self
    {
        $this->enumTypes[$paramName] = $enumClass;

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
                $passedParams = implode(', ', array_keys($input));
                throw new \InvalidArgumentException("Missing required parameter: {$param}. Received: [{$passedParams}]");
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
        // Convert enums (handle both single class and array of classes)
        // Only convert if the value is a scalar (string/int), not an array
        foreach ($this->enumTypes as $paramName => $enumClass) {
            if (isset($input[$paramName]) && !is_array($input[$paramName])) {
                if (is_array($enumClass)) {
                    // Multiple enum classes - try each one
                    foreach ($enumClass as $class) {
                        try {
                            $input[$paramName] = $class::from($input[$paramName]);
                            break; // Successfully converted
                        } catch (\ValueError $e) {
                            // Try next enum class
                            continue;
                        }
                    }
                } else {
                    $input[$paramName] = $enumClass::from($input[$paramName]);
                }
            }
        }

        // Convert DataModels (handle both single class and array of classes)
        foreach ($this->dataModelTypes as $paramName => $dataModelClass) {
            if (isset($input[$paramName]) && is_array($input[$paramName])) {
                if (is_array($dataModelClass)) {
                    // Multiple DataModel classes - find the best match based on properties
                    $inputKeys = array_keys($input[$paramName]);
                    $bestMatch = null;
                    $bestScore = -1;
                    
                    foreach ($dataModelClass as $class) {
                        try {
                            // Get the DataModel's expected properties
                            $schema = $class::generateSchema();
                            $expectedProps = array_keys($schema['properties'] ?? []);
                            $requiredProps = $schema['required'] ?? [];
                            
                            // Calculate match score based on property overlap
                            $matchingProps = array_intersect($inputKeys, $expectedProps);
                            $hasAllRequired = empty(array_diff($requiredProps, $inputKeys));
                            
                            // Score: matching properties count + bonus if all required present
                            $score = count($matchingProps) + ($hasAllRequired ? 100 : 0);
                            
                            if ($score > $bestScore) {
                                $bestScore = $score;
                                $bestMatch = $class;
                            }
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                    
                    // Use the best matching DataModel class
                    if ($bestMatch !== null) {
                        try {
                            $input[$paramName] = $bestMatch::fromArray($input[$paramName]);
                        } catch (\Throwable $e) {
                            // Failed to create instance
                        }
                    }
                } else {
                    $input[$paramName] = $dataModelClass::fromArray($input[$paramName]);
                }
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
