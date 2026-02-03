<?php

namespace LarAgent;

use LarAgent\Core\Abstractions\Tool as AbstractTool;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Helpers\SchemaGenerator;
use LarAgent\Core\Helpers\UnionTypeResolver;

class Tool extends AbstractTool implements ToolInterface
{
    protected mixed $callback = null;

    protected array $enumTypes = [];

    protected array $dataModelTypes = [];

    /**
     * When set, the entire tool input is treated as a DataModel.
     * The execute() method will receive a DataModel instance instead of an array.
     */
    protected ?string $rootDataModelClass = null;

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

    /**
     * Add a DataModel class to define all tool properties from its schema.
     *
     * When using this method, the tool's input will be treated as the entire DataModel,
     * and the callback will receive a DataModel instance instead of individual parameters.
     *
     * @param  string|DataModelContract  $dataModelOrClass  DataModel class name or instance
     * @return $this
     */
    public function addDataModelAsProperties(string|DataModelContract $dataModelOrClass): self
    {
        $className = is_object($dataModelOrClass) ? get_class($dataModelOrClass) : $dataModelOrClass;

        if (! is_subclass_of($className, DataModelContract::class)) {
            throw new \InvalidArgumentException("Class {$className} must implement DataModel contract.");
        }

        // Get the schema from the DataModel
        $schema = SchemaGenerator::forDataModel($className);

        // Extract properties from the schema
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $propName => $propSchema) {
                $this->properties[$propName] = $propSchema;
            }
        }

        // Extract required fields
        if (isset($schema['required']) && is_array($schema['required'])) {
            $this->required = array_unique(array_merge($this->required, $schema['required']));
        }

        // Mark this tool as using a root DataModel for input/output conversion
        $this->rootDataModelClass = $className;

        return $this;
    }

    /**
     * Add a single property that should be treated as a DataModel.
     *
     * This extracts the schema from the DataModel and registers it for automatic
     * conversion during tool execution.
     *
     * @param  string  $key  The property name
     * @param  string|DataModelContract  $dataModelOrClass  DataModel class name or instance
     * @param  string  $description  Optional description for the property
     * @return $this
     */
    public function addDataModelProperty(string $key, string|DataModelContract $dataModelOrClass, string $description = ''): self
    {
        $className = is_object($dataModelOrClass) ? get_class($dataModelOrClass) : $dataModelOrClass;

        if (! is_subclass_of($className, DataModelContract::class)) {
            throw new \InvalidArgumentException("Class {$className} must implement DataModel contract.");
        }

        // Get the schema from the DataModel
        $schema = SchemaGenerator::forDataModel($className);

        // Add description if provided
        if ($description) {
            $schema['description'] = $description;
        }

        // Add the property with the full schema
        $this->properties[$key] = $schema;

        // Register for automatic conversion
        $this->dataModelTypes[$key] = $className;

        return $this;
    }

    /**
     * Get the root DataModel class if set.
     *
     * @return string|null
     */
    public function getRootDataModelClass(): ?string
    {
        return $this->rootDataModelClass;
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

        // If this tool uses a root DataModel, convert the entire input to a DataModel instance
        if ($this->rootDataModelClass !== null) {
            $dataModelInstance = $this->rootDataModelClass::fromArray($input);

            return call_user_func($this->callback, $dataModelInstance);
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
        foreach ($input as $paramName => $value) {
            $dataModelClasses = $this->dataModelTypes[$paramName] ?? null;
            $enumClasses = $this->enumTypes[$paramName] ?? null;

            // Skip if no special types registered for this parameter
            if ($dataModelClasses === null && $enumClasses === null) {
                continue;
            }

            // Normalize to arrays
            $dataModelClasses = $dataModelClasses ? (array) $dataModelClasses : [];
            $enumClasses = $enumClasses ? (array) $enumClasses : [];

            // Use centralized resolver
            $input[$paramName] = UnionTypeResolver::resolveUnionValue(
                $value,
                $dataModelClasses,
                $enumClasses
            );
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
