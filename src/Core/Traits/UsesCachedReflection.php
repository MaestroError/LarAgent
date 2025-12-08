<?php

namespace LarAgent\Core\Traits;

use BackedEnum;
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

/**
 * Trait UsesCachedReflection
 *
 * Provides cached reflection-based operations for DataModel and Agent classes.
 * Encapsulates all reflection logic including type resolution, schema generation,
 * and value casting for consistent handling across the framework.
 */
trait UsesCachedReflection
{
    /**
     * Main reflection cache for DataModel configurations and type schemas
     *
     * @var array
     */
    protected static array $reflectionCache = [];

    /**
     * Get or build cached configuration for a DataModel class
     *
     * Caches reflection data including properties, constructor parameters, and type information.
     *
     * @return array Configuration array with properties, required fields, and constructor info
     */
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

    /**
     * Generate OpenAPI schema from cached configuration
     *
     * @return array OpenAPI schema with type, properties, and required fields
     */
    protected static function generateSchema(): array
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
     * Get property schema from cached property configuration
     *
     * @param  array  $propConfig  Property configuration from cache
     * @return array OpenAPI schema for the property
     */
    protected static function getPropertySchemaFromConfig(array $propConfig): array
    {
        $schema = static::reflectionTypeToSchema($propConfig['type']);

        if ($propConfig['description']) {
            $schema['description'] = $propConfig['description'];
        }

        return $schema;
    }

    /**
     * Cast a value to match a ReflectionType
     *
     * Handles union types, DataModel classes, enums, and builtin types.
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionType|null  $type  The target type
     * @return mixed The cast value
     */
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
                } elseif (is_subclass_of($typeName, UnitEnum::class) && (is_string($value) || is_int($value))) {
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
     * Check if a value can potentially be cast to a given type
     *
     * Used for union type casting to determine compatibility.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value can potentially be cast to the type
     */
    protected static function canCastToType(mixed $value, ReflectionType $type): bool
    {
        // Defensive check: Union types should only appear at the top level, not nested within another union.
        // If encountered, allow the recursive castValue call to handle it.
        if ($type instanceof ReflectionUnionType) {
            return true;
        }

        if (! $type instanceof ReflectionNamedType) {
            return true;
        }

        $typeName = $type->getName();

        // Builtin types - strict type checking
        if ($type->isBuiltin()) {
            return match ($typeName) {
                'int' => is_int($value),
                'float' => is_float($value),
                'bool' => is_bool($value),
                'string' => is_string($value),
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
     * Check if a value is valid for a given type
     *
     * Used for union type casting validation.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value is valid for the type
     */
    protected static function isValueValidForType(mixed $value, ReflectionType $type): bool
    {
        // Defensive check: Union types should never appear nested. Return false to prevent validation of impossible type combinations.
        if ($type instanceof ReflectionUnionType) {
            return false;
        }

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }
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

    /**
     * Convert a ReflectionType to OpenAPI schema format
     *
     * Handles builtin types, enums, DataModel classes, and union types.
     * Uses caching to avoid repeated reflection operations.
     *
     * @param  ReflectionType|null  $type  The reflection type to convert
     * @return array OpenAPI schema array (e.g., ['type' => 'string'])
     */
    protected static function reflectionTypeToSchema(?ReflectionType $type): array
    {
        if (! $type) {
            return ['type' => 'string'];
        }

        // Handle union types (e.g., string|int)
        if ($type instanceof ReflectionUnionType) {
            return static::unionTypeToSchema($type);
        }

        if ($type instanceof ReflectionNamedType) {
            return static::namedTypeToSchema($type);
        }

        return ['type' => 'string'];
    }

    /**
     * Convert a union type to OpenAPI schema format using oneOf
     *
     * @param  ReflectionUnionType  $unionType  The union type to convert
     * @return array OpenAPI schema with oneOf or single type
     */
    protected static function unionTypeToSchema(ReflectionUnionType $unionType): array
    {
        $schemas = [];
        foreach ($unionType->getTypes() as $subType) {
            // Skip null type as it's handled by OpenAPI's nullable property
            if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                continue;
            }
            $schemas[] = static::reflectionTypeToSchema($subType);
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

    /**
     * Convert a named type to OpenAPI schema format
     *
     * Handles builtin types, enums, and DataModel classes.
     *
     * @param  ReflectionNamedType  $namedType  The named type to convert
     * @return array OpenAPI schema array
     */
    protected static function namedTypeToSchema(ReflectionNamedType $namedType): array
    {
        $typeName = $namedType->getName();

        // Check cache first (use a sub-key for type schemas to avoid conflicts)
        $cacheKey = 'typeSchema:'.$typeName;
        if (isset(static::$reflectionCache[$cacheKey])) {
            return static::$reflectionCache[$cacheKey];
        }

        // Handle builtin types
        if ($namedType->isBuiltin()) {
            $schema = static::builtinTypeToSchema($typeName);
            static::$reflectionCache[$cacheKey] = $schema;

            return $schema;
        }

        // Handle DataModel classes
        if (is_subclass_of($typeName, DataModelContract::class)) {
            // Don't cache DataModel schemas as they may be dynamic
            return static::dataModelTypeToSchema($typeName);
        }

        // Handle Enums
        if (enum_exists($typeName)) {
            $schema = static::enumTypeToSchema($typeName);
            static::$reflectionCache[$cacheKey] = $schema;

            return $schema;
        }

        // Default fallback
        $schema = ['type' => 'string'];
        static::$reflectionCache[$cacheKey] = $schema;

        return $schema;
    }

    /**
     * Convert a builtin PHP type to OpenAPI type
     *
     * @param  string  $typeName  The builtin type name (int, float, bool, string, array, object)
     * @return array OpenAPI schema array
     */
    protected static function builtinTypeToSchema(string $typeName): array
    {
        return match ($typeName) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Convert an enum type to OpenAPI schema format
     *
     * Handles both backed enums (with scalar values) and unit enums (name only).
     *
     * @param  string  $enumClassName  The fully qualified enum class name
     * @return array OpenAPI schema with type and enum values
     */
    protected static function enumTypeToSchema(string $enumClassName): array
    {
        $reflectionEnum = new ReflectionEnum($enumClassName);
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

    /**
     * Convert a DataModel class to OpenAPI schema format
     *
     * @param  string  $dataModelClassName  The fully qualified DataModel class name
     * @return array OpenAPI schema (nested object schema)
     */
    protected static function dataModelTypeToSchema(string $dataModelClassName): array
    {
        if (method_exists($dataModelClassName, 'generateSchema')) {
            return $dataModelClassName::generateSchema();
        }

        try {
            $instance = (new ReflectionClass($dataModelClassName))->newInstanceWithoutConstructor();

            return $instance->toSchema();
        } catch (\Throwable $e) {
            return ['type' => 'object'];
        }
    }

    /**
     * Convert a type name string to OpenAPI schema format
     *
     * This is a convenience method for cases where we only have a type name string
     * (typically from older reflection code or simple type hints).
     *
     * @param  string  $typeName  The type name (e.g., 'string', 'int', or class name)
     * @return array|string OpenAPI schema array or simple type string
     */
    protected static function typeNameToSchema(string $typeName): array|string
    {
        // Check if it's an enum
        if (enum_exists($typeName)) {
            return static::enumTypeToSchema($typeName);
        }

        // Check if it's a DataModel
        if (is_subclass_of($typeName, DataModelContract::class)) {
            return static::dataModelTypeToSchema($typeName);
        }

        // Handle builtin types - return simple string for backward compatibility
        return match ($typeName) {
            'string' => 'string',
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    /**
     * Get type information with metadata for a ReflectionType
     *
     * Returns an array with 'schema', 'enumClass', and 'dataModelClass' for tool parameter handling.
     *
     * @param  ReflectionType|null  $type  The reflection type to analyze
     * @return array Type information with schema and metadata
     */
    protected static function getTypeInfo(?ReflectionType $type): array
    {
        if (! $type) {
            return [
                'schema' => ['type' => 'string'],
                'enumClass' => null,
                'dataModelClass' => null,
            ];
        }

        // Handle union types
        if ($type instanceof ReflectionUnionType) {
            $schemas = [];
            $enumClasses = [];
            $dataModelClasses = [];

            foreach ($type->getTypes() as $subType) {
                // Skip null type
                if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                    continue;
                }

                $subInfo = static::getTypeInfo($subType);
                $schemas[] = $subInfo['schema'];
                if ($subInfo['enumClass']) {
                    $enumClasses[] = $subInfo['enumClass'];
                }
                if ($subInfo['dataModelClass']) {
                    $dataModelClasses[] = $subInfo['dataModelClass'];
                }
            }

            if (count($schemas) > 1) {
                return [
                    'schema' => ['oneOf' => $schemas],
                    'enumClass' => ! empty($enumClasses) ? $enumClasses : null,
                    'dataModelClass' => ! empty($dataModelClasses) ? $dataModelClasses : null,
                ];
            }

            if (count($schemas) === 1) {
                return [
                    'schema' => $schemas[0],
                    'enumClass' => ! empty($enumClasses) ? $enumClasses[0] : null,
                    'dataModelClass' => ! empty($dataModelClasses) ? $dataModelClasses[0] : null,
                ];
            }

            return [
                'schema' => ['type' => 'string'],
                'enumClass' => null,
                'dataModelClass' => null,
            ];
        }

        // Handle named types
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            $schema = static::namedTypeToSchema($type);

            // Check if it's an enum
            if (enum_exists($typeName)) {
                return [
                    'schema' => $schema,
                    'enumClass' => $typeName,
                    'dataModelClass' => null,
                ];
            }

            // Check if it's a DataModel
            if (is_subclass_of($typeName, DataModelContract::class)) {
                return [
                    'schema' => $schema,
                    'enumClass' => null,
                    'dataModelClass' => $typeName,
                ];
            }

            return [
                'schema' => $schema,
                'enumClass' => null,
                'dataModelClass' => null,
            ];
        }

        return [
            'schema' => ['type' => 'string'],
            'enumClass' => null,
            'dataModelClass' => null,
        ];
    }

    /**
     * Clear the reflection cache
     *
     * Useful for testing or when type definitions change at runtime.
     *
     * @return void
     */
    protected static function clearReflectionCache(): void
    {
        static::$reflectionCache = [];
    }
}
