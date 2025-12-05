<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use BackedEnum;
use JsonSerializable;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

abstract class DataModel implements ArrayAccess, DataModelContract, JsonSerializable
{
    protected static array $reflectionCache = [];

    /**
     * Convert the model to an array.
     */
    public function toArray(): array
    {
        $result = [];
        $config = static::getCachedConfig();

        foreach ($config['properties'] as $name => $propConfig) {
            /** @var ReflectionProperty $reflection */
            $reflection = $propConfig['reflection'];

            if (! $reflection->isInitialized($this)) {
                continue;
            }

            $value = $this->{$name};

            if ($value instanceof DataModelContract) {
                $result[$name] = $value->toArray();
            } elseif (is_array($value)) {
                $result[$name] = array_map(function ($item) {
                    return $item instanceof DataModelContract ? $item->toArray() : $item;
                }, $value);
            } elseif ($value instanceof UnitEnum) {
                $result[$name] = $value instanceof BackedEnum ? $value->value : $value->name;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Generate the OpenAPI schema for the model.
     */
    public function toSchema(): array
    {
        return static::generateSchema();
    }

    /**
     * Generate the OpenAPI schema for the model statically.
     */
    public static function generateSchema(): array
    {
        $config = static::getCachedConfig();
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => $config['required'],
        ];

        foreach ($config['properties'] as $name => $propConfig) {
            // Skip properties marked with #[ExcludeFromSchema]
            if ($propConfig['excludeFromSchema'] ?? false) {
                continue;
            }

            $propertySchema = static::getPropertySchemaFromConfig($propConfig);
            if ($propertySchema) {
                $schema['properties'][$name] = $propertySchema;
            }
        }

        // Also remove excluded properties from required
        $schema['required'] = array_values(array_filter(
            $schema['required'],
            fn ($name) => ! ($config['properties'][$name]['excludeFromSchema'] ?? false)
        ));

        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Fill the model with an array of attributes.
     */
    public function fill(array $attributes): static
    {
        $config = static::getCachedConfig();

        foreach ($attributes as $key => $value) {
            if (! isset($config['properties'][$key])) {
                continue;
            }

            $propConfig = $config['properties'][$key];

            // Skip if property is readonly and already initialized
            /** @var ReflectionProperty $reflection */
            $reflection = $propConfig['reflection'];
            if (method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly() && $reflection->isInitialized($this)) {
                continue;
            }

            $value = static::castValue($value, $propConfig['type']);

            $this->{$key} = $value;
        }

        return $this;
    }

    /**
     * Create a new instance from an array of attributes.
     */
    public static function fromArray(array $attributes): static
    {
        $config = static::getCachedConfig();

        if ($config['constructor']) {
            $args = [];
            foreach ($config['constructor'] as $param) {
                $name = $param['name'];
                if (array_key_exists($name, $attributes)) {
                    $value = static::castValue($attributes[$name], $param['type']);
                    $args[] = $value;
                } else {
                    if ($param['hasDefault']) {
                        $args[] = $param['default'];
                    } elseif ($param['allowsNull']) {
                        $args[] = null;
                    } else {
                        throw new \InvalidArgumentException("Missing required constructor parameter: {$name}");
                    }
                }
            }
            $instance = new static(...$args);
            $instance->fill($attributes);

            return $instance;
        }

        $instance = new static;
        $instance->fill($attributes);

        return $instance;
    }

    protected static function castValue(mixed $value, ?ReflectionType $type): mixed
    {
        // Handle union types by trying each type in order
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                // Skip null type
                if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                    continue;
                }
                
                // Check if this type is potentially compatible before trying to cast
                if (! static::canCastToType($value, $subType)) {
                    continue;
                }
                
                // Try to cast to this type
                $castValue = static::castValue($value, $subType);
                
                // If casting was successful (value changed or is valid for the type), return it
                if ($castValue !== $value || static::isValueValidForType($value, $subType)) {
                    return $castValue;
                }
            }
            
            // If no casting worked, return original value
            return $value;
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $typeName = $type->getName();

            if (is_subclass_of($typeName, DataModelContract::class) && is_array($value)) {
                return $typeName::fromArray($value);
            } elseif (enum_exists($typeName)) {
                if ($value instanceof $typeName) {
                    return $value;
                } elseif (is_subclass_of($typeName, BackedEnum::class) && (is_string($value) || is_int($value))) {
                    return $typeName::tryFrom($value) ?? $value;
                } elseif (is_subclass_of($typeName, UnitEnum::class) && is_string($value)) {
                    foreach ($typeName::cases() as $case) {
                        if ($case->name === $value) {
                            return $case;
                        }
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Check if a value can potentially be cast to a given type (used for union type casting).
     */
    protected static function canCastToType(mixed $value, ReflectionNamedType $type): bool
    {
        $typeName = $type->getName();

        // Builtin types - check if already that type
        if ($type->isBuiltin()) {
            return match ($typeName) {
                'int' => is_int($value) || is_numeric($value),
                'float' => is_float($value) || is_numeric($value),
                'bool' => is_bool($value),
                'string' => is_string($value) || is_scalar($value),
                'array' => is_array($value),
                default => true,
            };
        }

        // DataModel - needs array or already instance
        if (is_subclass_of($typeName, DataModelContract::class)) {
            return is_array($value) || $value instanceof $typeName;
        }

        // Enum - needs string/int or already instance
        if (enum_exists($typeName)) {
            return $value instanceof $typeName || is_string($value) || is_int($value);
        }

        return true;
    }

    /**
     * Check if a value is valid for a given type (used for union type casting).
     */
    protected static function isValueValidForType(mixed $value, ReflectionNamedType $type): bool
    {
        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            return match ($typeName) {
                'int' => is_int($value),
                'float' => is_float($value),
                'bool' => is_bool($value),
                'string' => is_string($value),
                'array' => is_array($value),
                default => false,
            };
        }

        if (is_subclass_of($typeName, DataModelContract::class)) {
            return $value instanceof $typeName;
        }

        if (enum_exists($typeName)) {
            return $value instanceof $typeName;
        }

        return false;
    }

    protected static function getCachedConfig(): array
    {
        $class = static::class;
        if (isset(static::$reflectionCache[$class])) {
            return static::$reflectionCache[$class];
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $config = [
            'properties' => [],
            'required' => [],
            'constructor' => null,
        ];

        foreach ($properties as $property) {
            $name = $property->getName();
            $type = $property->getType();

            // Determine if required
            if ($type && ! $type->allowsNull() && ! $property->hasDefaultValue()) {
                $config['required'][] = $name;
            }

            $descAttributes = $property->getAttributes(Desc::class);
            $description = ! empty($descAttributes) ? $descAttributes[0]->newInstance()->description : null;

            $excludeAttributes = $property->getAttributes(ExcludeFromSchema::class);

            $config['properties'][$name] = [
                'reflection' => $property,
                'type' => $type,
                'description' => $description,
                'excludeFromSchema' => ! empty($excludeAttributes),
            ];
        }

        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->getNumberOfParameters() > 0) {
            $config['constructor'] = [];
            foreach ($constructor->getParameters() as $param) {
                $config['constructor'][] = [
                    'name' => $param->getName(),
                    'type' => $param->getType(),
                    'allowsNull' => $param->allowsNull(),
                    'hasDefault' => $param->isDefaultValueAvailable(),
                    'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
        }

        static::$reflectionCache[$class] = $config;

        return $config;
    }

    protected static function getPropertySchemaFromConfig(array $propConfig): array
    {
        $schema = static::getTypeSchemaFromType($propConfig['type']);

        if ($propConfig['description']) {
            $schema['description'] = $propConfig['description'];
        }

        return $schema;
    }

    protected static function getTypeSchemaFromType(?ReflectionType $type): array
    {
        if (! $type) {
            return ['type' => 'string'];
        }

        // Handle union types (e.g., string|int)
        if ($type instanceof ReflectionUnionType) {
            $schemas = [];
            foreach ($type->getTypes() as $subType) {
                // Skip null type as it's handled by OpenAPI's nullable property
                if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                    continue;
                }
                $schemas[] = static::getTypeSchemaFromType($subType);
            }

            // If we have multiple schemas, use oneOf
            if (count($schemas) > 1) {
                return ['oneOf' => $schemas];
            }

            // If only one schema (after filtering out null), return it directly
            if (count($schemas) === 1) {
                return $schemas[0];
            }

            // Fallback if all types were null (shouldn't happen in practice)
            return ['type' => 'string'];
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            if ($type->isBuiltin()) {
                return match ($typeName) {
                    'int' => ['type' => 'integer'],
                    'float' => ['type' => 'number'],
                    'bool' => ['type' => 'boolean'],
                    'array' => ['type' => 'array'],
                    default => ['type' => 'string'],
                };
            }

            if (is_subclass_of($typeName, DataModelContract::class)) {
                if (method_exists($typeName, 'generateSchema')) {
                    return $typeName::generateSchema();
                }
                try {
                    $instance = (new ReflectionClass($typeName))->newInstanceWithoutConstructor();

                    return $instance->toSchema();
                } catch (\Throwable $e) {
                    return ['type' => 'object'];
                }
            }

            if (enum_exists($typeName)) {
                $reflectionEnum = new ReflectionEnum($typeName);
                $cases = $reflectionEnum->getCases();
                $values = [];
                $schemaType = 'string';

                if ($reflectionEnum->isBacked()) {
                    $backingType = $reflectionEnum->getBackingType()->getName();
                    $schemaType = $backingType === 'int' ? 'integer' : 'string';
                    $values = array_map(fn ($case) => $case->getBackingValue(), $cases);
                } else {
                    $values = array_map(fn ($case) => $case->getName(), $cases);
                }

                return [
                    'type' => $schemaType,
                    'enum' => $values,
                ];
            }
        }

        return ['type' => 'string'];
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset) && isset($this->$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            return;
        }
        $this->fill([$offset => $value]);
    }

    /**
     * Unset an offset.
     *
     * Note: Unsetting a typed property without default value will leave it uninitialized.
     * Accessing it afterwards will throw an Error. The property still exists but isset() returns false.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }

    /**
     * Serialize the object to a value that can be natively JSON encoded.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
