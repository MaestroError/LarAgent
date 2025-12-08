<?php

namespace LarAgent\Core\Traits;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Trait UsesCachedReflection
 *
 * Provides cached reflection-based type resolution for OpenAPI schema generation.
 * Shared between DataModel and Agent classes to ensure consistent type handling.
 */
trait UsesCachedReflection
{
    /**
     * Cache for reflection data to avoid repeated reflection operations
     *
     * @var array
     */
    protected static array $typeReflectionCache = [];

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

        // Check cache first
        $cacheKey = 'namedType:'.$typeName;
        if (isset(static::$typeReflectionCache[$cacheKey])) {
            return static::$typeReflectionCache[$cacheKey];
        }

        // Handle builtin types
        if ($namedType->isBuiltin()) {
            $schema = static::builtinTypeToSchema($typeName);
            static::$typeReflectionCache[$cacheKey] = $schema;

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
            static::$typeReflectionCache[$cacheKey] = $schema;

            return $schema;
        }

        // Default fallback
        $schema = ['type' => 'string'];
        static::$typeReflectionCache[$cacheKey] = $schema;

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
     * Clear the type reflection cache
     *
     * Useful for testing or when type definitions change at runtime.
     *
     * @return void
     */
    protected static function clearTypeReflectionCache(): void
    {
        static::$typeReflectionCache = [];
    }
}
