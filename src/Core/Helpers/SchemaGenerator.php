<?php

namespace LarAgent\Core\Helpers;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * SchemaGenerator - Converts PHP types to OpenAPI schema format
 *
 * This helper class centralizes all logic for generating OpenAPI-compatible JSON schemas
 * from PHP type information. It handles builtin types, enums, DataModel classes, and
 * union types (generating `oneOf` schemas where appropriate).
 *
 * Usage:
 *   $schema = SchemaGenerator::fromReflectionType($reflectionType);
 *   $schema = SchemaGenerator::fromTypeName('string');
 *   $schema = SchemaGenerator::forEnum(MyEnum::class);
 *   $schema = SchemaGenerator::forDataModel(MyDataModel::class);
 *
 * @package LarAgent\Core\Helpers
 */
class SchemaGenerator
{
    /**
     * Cache for generated schemas to avoid repeated reflection operations
     *
     * @var array<string, array>
     */
    protected static array $schemaCache = [];

    /**
     * Convert a ReflectionType to OpenAPI schema format
     *
     * This is the main entry point for schema generation. It handles all PHP type variants
     * including builtin types, named types (classes/enums), and union types.
     *
     * @param  ReflectionType|null  $type  The reflection type to convert
     * @return array OpenAPI schema array (e.g., ['type' => 'string'] or ['oneOf' => [...]])
     */
    public static function fromReflectionType(?ReflectionType $type): array
    {
        if (! $type) {
            return ['type' => 'string'];
        }

        // Handle union types (e.g., string|int)
        if ($type instanceof ReflectionUnionType) {
            return static::fromUnionType($type);
        }

        if ($type instanceof ReflectionNamedType) {
            return static::fromNamedType($type);
        }

        return ['type' => 'string'];
    }

    /**
     * Convert a union type to OpenAPI schema format using oneOf
     *
     * @param  ReflectionUnionType  $unionType  The union type to convert
     * @return array OpenAPI schema with oneOf or single type
     */
    public static function fromUnionType(ReflectionUnionType $unionType): array
    {
        $schemas = [];
        foreach ($unionType->getTypes() as $subType) {
            // Skip null type as it's handled by OpenAPI's nullable property
            if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                continue;
            }
            $schemas[] = static::fromReflectionType($subType);
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
     * Handles builtin types, enums, and DataModel classes with caching.
     *
     * @param  ReflectionNamedType  $namedType  The named type to convert
     * @return array OpenAPI schema array
     */
    public static function fromNamedType(ReflectionNamedType $namedType): array
    {
        $typeName = $namedType->getName();

        // Check cache first
        if (isset(static::$schemaCache[$typeName])) {
            return static::$schemaCache[$typeName];
        }

        // Handle builtin types
        if ($namedType->isBuiltin()) {
            $schema = static::forBuiltinType($typeName);
            static::$schemaCache[$typeName] = $schema;

            return $schema;
        }

        // Handle DataModel classes (don't cache as they may be dynamic)
        if (is_subclass_of($typeName, DataModelContract::class)) {
            return static::forDataModel($typeName);
        }

        // Handle Enums
        if (enum_exists($typeName)) {
            $schema = static::forEnum($typeName);
            static::$schemaCache[$typeName] = $schema;

            return $schema;
        }

        // Default fallback
        $schema = ['type' => 'string'];
        static::$schemaCache[$typeName] = $schema;

        return $schema;
    }

    /**
     * Convert a type name string to OpenAPI schema format
     *
     * Convenience method for cases where we only have a type name string.
     *
     * @param  string  $typeName  The type name (e.g., 'string', 'int', or class name)
     * @return array|string OpenAPI schema array or simple type string
     */
    public static function fromTypeName(string $typeName): array|string
    {
        // Check if it's an enum
        if (enum_exists($typeName)) {
            return static::forEnum($typeName);
        }

        // Check if it's a DataModel
        if (is_subclass_of($typeName, DataModelContract::class)) {
            return static::forDataModel($typeName);
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
     * Generate OpenAPI schema for a builtin PHP type
     *
     * @param  string  $typeName  The builtin type name (int, float, bool, string, array, object)
     * @return array OpenAPI schema array
     */
    public static function forBuiltinType(string $typeName): array
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
     * Generate OpenAPI schema for an enum type
     *
     * Handles both backed enums (with scalar values) and unit enums (name only).
     *
     * @param  string  $enumClassName  The fully qualified enum class name
     * @return array OpenAPI schema with type and enum values
     */
    public static function forEnum(string $enumClassName): array
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
     * Generate OpenAPI schema for a DataModel class
     *
     * @param  string  $dataModelClassName  The fully qualified DataModel class name
     * @return array OpenAPI schema (nested object schema)
     */
    public static function forDataModel(string $dataModelClassName): array
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
     * Clear the schema cache
     *
     * Useful for testing or when type definitions change at runtime.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$schemaCache = [];
    }
}
