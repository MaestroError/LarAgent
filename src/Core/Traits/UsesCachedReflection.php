<?php

namespace LarAgent\Core\Traits;

use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Helpers\SchemaGenerator;
use LarAgent\Core\Helpers\TypeCaster;
use LarAgent\Core\Helpers\TypeInfoExtractor;
use LarAgent\Core\Helpers\UnionTypeResolver;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

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
    protected static function generateSchemaFromTrait(): array
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
     * Delegates to TypeCaster helper for centralized type casting logic.
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionType|null  $type  The target type
     * @return mixed The cast value
     */
    protected static function castValue(mixed $value, ?ReflectionType $type): mixed
    {
        return TypeCaster::cast($value, $type);
    }

    /**
     * Cast a value for a union type with smart DataModel matching
     *
     * Delegates to TypeCaster helper for centralized logic.
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionUnionType  $type  The union type
     * @return mixed The cast value
     */
    protected static function castUnionType(mixed $value, ReflectionUnionType $type): mixed
    {
        return TypeCaster::castUnionType($value, $type);
    }

    /**
     * Find the best matching DataModel class for an array value
     *
     * Delegates to UnionTypeResolver for centralized logic.
     *
     * @param  array  $value  The input array
     * @param  array  $dataModelClasses  Array of DataModel class names
     * @return string|null The best matching class name, or null if no match
     */
    protected static function findBestDataModelMatch(array $value, array $dataModelClasses): ?string
    {
        return UnionTypeResolver::findBestDataModelMatch($value, $dataModelClasses);
    }

    /**
     * Check if a value can potentially be cast to a given type
     *
     * Delegates to TypeCaster helper.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value can potentially be cast to the type
     */
    protected static function canCastToType(mixed $value, ReflectionType $type): bool
    {
        return TypeCaster::canCast($value, $type);
    }

    /**
     * Check if a value is valid for a given type
     *
     * Delegates to TypeCaster helper.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value is valid for the type
     */
    protected static function isValueValidForType(mixed $value, ReflectionType $type): bool
    {
        return TypeCaster::isValidForType($value, $type);
    }

    /**
     * Convert a ReflectionType to OpenAPI schema format
     *
     * Delegates to SchemaGenerator helper for centralized schema generation.
     *
     * @param  ReflectionType|null  $type  The reflection type to convert
     * @return array OpenAPI schema array (e.g., ['type' => 'string'])
     */
    protected static function reflectionTypeToSchema(?ReflectionType $type): array
    {
        return SchemaGenerator::fromReflectionType($type);
    }

    /**
     * Convert a union type to OpenAPI schema format using oneOf
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  ReflectionUnionType  $unionType  The union type to convert
     * @return array OpenAPI schema with oneOf or single type
     */
    protected static function unionTypeToSchema(ReflectionUnionType $unionType): array
    {
        return SchemaGenerator::fromUnionType($unionType);
    }

    /**
     * Convert a named type to OpenAPI schema format
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  ReflectionNamedType  $namedType  The named type to convert
     * @return array OpenAPI schema array
     */
    protected static function namedTypeToSchema(ReflectionNamedType $namedType): array
    {
        return SchemaGenerator::fromNamedType($namedType);
    }

    /**
     * Convert a builtin PHP type to OpenAPI type
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  string  $typeName  The builtin type name (int, float, bool, string, array, object)
     * @return array OpenAPI schema array
     */
    protected static function builtinTypeToSchema(string $typeName): array
    {
        return SchemaGenerator::forBuiltinType($typeName);
    }

    /**
     * Convert an enum type to OpenAPI schema format
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  string  $enumClassName  The fully qualified enum class name
     * @return array OpenAPI schema with type and enum values
     */
    protected static function enumTypeToSchema(string $enumClassName): array
    {
        return SchemaGenerator::forEnum($enumClassName);
    }

    /**
     * Convert a DataModel class to OpenAPI schema format
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  string  $dataModelClassName  The fully qualified DataModel class name
     * @return array OpenAPI schema (nested object schema)
     */
    protected static function dataModelTypeToSchema(string $dataModelClassName): array
    {
        return SchemaGenerator::forDataModel($dataModelClassName);
    }

    /**
     * Convert a type name string to OpenAPI schema format
     *
     * Delegates to SchemaGenerator helper.
     *
     * @param  string  $typeName  The type name (e.g., 'string', 'int', or class name)
     * @return array|string OpenAPI schema array or simple type string
     */
    protected static function typeNameToSchema(string $typeName): array|string
    {
        return SchemaGenerator::fromTypeName($typeName);
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
            $enumClasses = TypeInfoExtractor::getEnumClasses($type);
            $dataModelClasses = TypeInfoExtractor::getClassesOfType($type, DataModelContract::class);

            // Build schemas for each non-null type
            foreach ($type->getTypes() as $subType) {
                // Skip null type
                if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                    continue;
                }
                $schemas[] = SchemaGenerator::fromReflectionType($subType);
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
            $schema = SchemaGenerator::fromNamedType($type);

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
     * Get cached public methods with a specific attribute
     *
     * @param  string  $attributeClass  The attribute class to filter methods by
     * @return array Array of ReflectionMethod objects with the specified attribute
     */
    protected static function getCachedMethodsWithAttribute(string $attributeClass): array
    {
        $class = static::class;
        $cacheKey = $class.':methods:'.$attributeClass;

        if (isset(static::$reflectionCache[$cacheKey])) {
            return static::$reflectionCache[$cacheKey];
        }

        $reflection = new ReflectionClass($class);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes($attributeClass);
            if (! empty($attributes)) {
                $methods[] = $method;
            }
        }

        static::$reflectionCache[$cacheKey] = $methods;

        return $methods;
    }

    /**
     * Clear the reflection cache
     *
     * Clears both the trait's local cache and the helper classes' caches.
     * Useful for testing or when type definitions change at runtime.
     *
     * @return void
     */
    protected static function clearReflectionCache(): void
    {
        static::$reflectionCache = [];
        SchemaGenerator::clearCache();
    }
}
