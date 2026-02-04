<?php

namespace LarAgent;

use LarAgent\Core\Abstractions\Tool as AbstractTool;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Helpers\SchemaGenerator;
use LarAgent\Core\Helpers\UnionTypeResolver;
use LarAgent\Exceptions\InvalidDataModelException;

class Tool extends AbstractTool implements ToolInterface
{
    protected mixed $callback = null;

    protected array $enumTypes = [];

    protected array $dataModelTypes = [];

    /**
     * When set, the entire tool input is treated as a DataModel.
     * For callback-based tools, the callback will receive a DataModel instance.
     * For class-based tools that override handle(), will receive a DataModel instance.
     */
    protected ?string $rootDataModelClass = null;

    /**
     * Optional DataModel class to use as the schema source for all properties.
     * Set this in child classes to automatically populate properties from a DataModel.
     *
     * Example:
     *   protected ?string $dataModelClass = TaskDataModel::class;
     */
    protected ?string $dataModelClass = null;

    public function __construct(?string $name = null, ?string $description = null)
    {
        $this->name = $name ?? $this->name;
        $this->description = $description ?? $this->description;
        parent::__construct($this->name, $this->description);

        // Process dataModelClass if set on the child class
        $this->initializeDataModelProperties();
    }

    /**
     * Initialize properties from dataModelClass if set.
     *
     * This method processes the $dataModelClass property and also checks
     * for any DataModel class names in the $properties array.
     *
     * @throws InvalidDataModelException if $dataModelClass is set to an invalid class
     */
    protected function initializeDataModelProperties(): void
    {
        // If dataModelClass is set, validate and use it to populate all properties
        if ($this->dataModelClass !== null) {
            $this->validateDataModelClass(
                $this->dataModelClass,
                fn ($class) => InvalidDataModelException::invalidDataModelClassProperty($class)
            );
            $this->addDataModelAsProperties($this->dataModelClass);

            return;
        }

        // Check for DataModel class names in properties array
        $this->processPropertiesWithDataModels();
    }

    /**
     * Override setProperties to automatically process DataModel class names.
     *
     * Note: If rootDataModelClass was previously set, this will clear it.
     *
     * @param  array  $props  Properties array, which may contain DataModel class names
     * @return $this
     */
    public function setProperties(array $props): self
    {
        // Clear rootDataModelClass as we're replacing all properties
        $this->rootDataModelClass = null;

        parent::setProperties($props);
        $this->processPropertiesWithDataModels();

        return $this;
    }

    /**
     * Override addProperty to clear rootDataModelClass when adding individual properties.
     *
     * @param  string  $name  Property name
     * @param  string|array  $type  Property type or schema
     * @param  string  $description  Optional description
     * @param  array  $enum  Optional enum values
     * @return $this
     */
    public function addProperty(string $name, string|array $type, string $description = '', array $enum = []): self
    {
        // Clear rootDataModelClass as we're adding individual properties
        $this->rootDataModelClass = null;

        return parent::addProperty($name, $type, $description, $enum);
    }

    /**
     * Process properties array to detect and expand DataModel class names.
     *
     * This allows patterns like:
     *   $properties = ['task' => TaskDataModel::class]
     *   $properties = ['task' => TaskDataModel::class, 'name' => ['type' => 'string']]
     */
    protected function processPropertiesWithDataModels(): void
    {
        $newProperties = [];

        foreach ($this->properties as $key => $value) {
            // If the value is a string, check if it's a valid DataModel class name
            if (
                is_string($value)
                && class_exists($value)
                && is_subclass_of($value, DataModelContract::class)
            ) {
                // Get the schema from the DataModel
                $schema = SchemaGenerator::forDataModel($value);
                $newProperties[$key] = $schema;

                // Register for automatic conversion
                $this->dataModelTypes[$key] = $value;
            } else {
                $newProperties[$key] = $value;
            }
        }

        $this->properties = $newProperties;
    }

    /**
     * Validate that a class implements the DataModel contract.
     *
     * @param  string|DataModelContract  $dataModelOrClass  DataModel class name or instance
     * @param  callable|null  $exceptionFactory  Optional custom exception factory
     * @return string The validated class name
     *
     * @throws InvalidDataModelException if the class doesn't implement DataModel contract
     */
    protected function validateDataModelClass(string|DataModelContract $dataModelOrClass, ?callable $exceptionFactory = null): string
    {
        $className = is_object($dataModelOrClass) ? get_class($dataModelOrClass) : $dataModelOrClass;

        if (! class_exists($className) || ! is_subclass_of($className, DataModelContract::class)) {
            throw $exceptionFactory
                ? $exceptionFactory($className)
                : InvalidDataModelException::notADataModel($className);
        }

        return $className;
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
     * For class-based tools, use handle() method to get the DataModel instance.
     *
     * Note: Calling this method clears any previously defined properties and required fields.
     *
     * @param  string|DataModelContract  $dataModelOrClass  DataModel class name or instance
     * @return $this
     *
     * @throws InvalidDataModelException if the class doesn't implement DataModel contract
     */
    public function addDataModelAsProperties(string|DataModelContract $dataModelOrClass): self
    {
        $className = $this->validateDataModelClass($dataModelOrClass);

        // Clear any previously defined properties, required fields, and dataModel types
        $this->properties = [];
        $this->required = [];
        $this->dataModelTypes = [];

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
            $this->required = $schema['required'];
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
     * Note: If rootDataModelClass was previously set via addDataModelAsProperties(),
     * calling this method will clear it as it indicates mixed property usage.
     *
     * @param  string  $key  The property name
     * @param  string|DataModelContract  $dataModelOrClass  DataModel class name or instance
     * @param  string  $description  Optional description for the property
     * @return $this
     *
     * @throws InvalidDataModelException if the class doesn't implement DataModel contract
     */
    public function addDataModelProperty(string $key, string|DataModelContract $dataModelOrClass, string $description = ''): self
    {
        $className = $this->validateDataModelClass($dataModelOrClass);

        // Clear rootDataModelClass as we're adding individual properties
        $this->rootDataModelClass = null;

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
     */
    public function getRootDataModelClass(): ?string
    {
        return $this->rootDataModelClass;
    }

    /**
     * Convert input array to DataModel instance if rootDataModelClass is set.
     *
     * This method is useful for class-based tools that override execute().
     * Call this method to convert the raw input array to a DataModel instance.
     *
     * @param  array  $input  The raw input array
     * @return DataModelContract|array Returns DataModel instance if rootDataModelClass is set, otherwise the original array
     */
    protected function convertInputToDataModel(array $input): DataModelContract|array
    {
        if ($this->rootDataModelClass !== null) {
            return $this->rootDataModelClass::fromArray($input);
        }

        return $input;
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

    /**
     * Execute the tool with the given input.
     *
     * This method validates required parameters, converts special types (DataModel, Enum),
     * and delegates to handle() for the actual tool logic.
     *
     * For class-based tools, override handle() instead of execute() to receive
     * automatically converted DataModel and Enum instances.
     *
     * @param  array  $input  Raw input array from the LLM
     * @return mixed Tool execution result
     */
    public function execute(array $input): mixed
    {
        // Validate required parameters
        foreach ($this->required as $param) {
            if (! array_key_exists($param, $input)) {
                $passedParams = implode(', ', array_keys($input));
                throw new \InvalidArgumentException("Missing required parameter: {$param}. Received: [{$passedParams}]");
            }
        }

        // If this tool uses a root DataModel, convert entire input to DataModel instance
        if ($this->rootDataModelClass !== null) {
            $dataModelInstance = $this->rootDataModelClass::fromArray($input);

            return $this->handle($dataModelInstance);
        }

        // Convert enum string values to actual enum instances and DataModel arrays to instances
        $convertedInput = $this->convertSpecialTypes($input);

        // Delegate to handle() for the actual tool logic
        return $this->handle($convertedInput);
    }

    /**
     * Handle the tool execution with prepared input.
     *
     * Override this method in class-based tools to receive automatically converted
     * DataModel and Enum instances.
     *
     * When using $dataModelClass or addDataModelAsProperties():
     * - $input is a DataModel instance (the entire input as one object)
     *
     * When using $properties with DataModel classes or addDataModelProperty():
     * - $input is an array with individual properties converted to DataModel instances
     *
     * @param  array|DataModelContract  $input  Prepared input (DataModel instance or array with converted types)
     * @return mixed Tool execution result
     */
    protected function handle(array|DataModelContract $input): mixed
    {
        if ($this->callback === null) {
            throw new \BadMethodCallException('No callback defined for execution. Override handle() method or set a callback.');
        }

        // If input is a DataModel instance (rootDataModelClass was set), pass it directly
        if ($input instanceof DataModelContract) {
            return call_user_func($this->callback, $input);
        }

        // Execute the callback with converted input array
        return call_user_func($this->callback, ...$input);
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
