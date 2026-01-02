<?php

namespace LarAgent\Core\Helpers;

use BackedEnum;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * TypeCaster - Casts values to match PHP types with smart union type handling
 *
 * This helper class centralizes all logic for casting raw values (typically from JSON/API responses)
 * to their appropriate PHP types. It handles builtin types, enums, DataModel classes, and
 * union types with intelligent matching to select the best type for the given value.
 *
 * For union types with multiple DataModels or Enums, it delegates to UnionTypeResolver
 * for smart matching based on property/value compatibility.
 *
 * Usage:
 *   $typed = TypeCaster::cast($value, $reflectionType);
 *   $canCast = TypeCaster::canCast($value, $reflectionType);
 *   $isValid = TypeCaster::isValidForType($value, $reflectionType);
 */
class TypeCaster
{
    /**
     * Cast a value to match a ReflectionType
     *
     * This is the main entry point for type casting. It handles union types with smart matching,
     * DataModel classes (converting arrays to instances), enums (converting strings/ints to cases),
     * and builtin types.
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionType|null  $type  The target type
     * @return mixed The cast value (or original if no casting needed/possible)
     */
    public static function cast(mixed $value, ?ReflectionType $type): mixed
    {
        if ($type === null) {
            return $value;
        }

        // Handle union types with smart DataModel/Enum matching
        if ($type instanceof ReflectionUnionType) {
            return static::castUnionType($value, $type);
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return static::castNamedType($value, $type);
        }

        return $value;
    }

    /**
     * Cast a value for a union type with smart DataModel/Enum matching
     *
     * When multiple DataModels are in a union, uses UnionTypeResolver to select
     * the best match based on which DataModel's required properties match the input.
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionUnionType  $type  The union type
     * @return mixed The cast value
     */
    public static function castUnionType(mixed $value, ReflectionUnionType $type): mixed
    {
        // Collect DataModel and Enum classes from the union for smart matching
        $dataModelClasses = [];
        $enumClasses = [];

        foreach ($type->getTypes() as $subType) {
            // Skip null type
            if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                continue;
            }

            if ($subType instanceof ReflectionNamedType && ! $subType->isBuiltin()) {
                $typeName = $subType->getName();
                if (is_subclass_of($typeName, DataModelContract::class)) {
                    $dataModelClasses[] = $typeName;

                    continue;
                }
                if (enum_exists($typeName)) {
                    $enumClasses[] = $typeName;

                    continue;
                }
            }
        }

        // Use centralized resolver for DataModel and Enum matching
        if (! empty($dataModelClasses) || ! empty($enumClasses)) {
            $resolved = UnionTypeResolver::resolveUnionValue($value, $dataModelClasses, $enumClasses);
            if ($resolved !== $value) {
                return $resolved;
            }
        }

        // For other types (builtins, etc.), try each type in order
        foreach ($type->getTypes() as $subType) {
            // Skip null type
            if ($subType instanceof ReflectionNamedType && $subType->getName() === 'null') {
                continue;
            }

            // Skip DataModels and Enums (already handled above)
            if ($subType instanceof ReflectionNamedType && ! $subType->isBuiltin()) {
                $typeName = $subType->getName();
                if (is_subclass_of($typeName, DataModelContract::class) || enum_exists($typeName)) {
                    continue;
                }
            }

            // Check if this type is potentially compatible before trying to cast
            if (! static::canCast($value, $subType)) {
                continue;
            }

            // Try to cast to this type
            $castValue = static::cast($value, $subType);

            // If casting was successful (value changed or is valid for the type), return it
            if ($castValue !== $value || static::isValidForType($value, $subType)) {
                return $castValue;
            }
        }

        // If no casting worked, return original value
        return $value;
    }

    /**
     * Cast a value to a named (non-builtin) type
     *
     * @param  mixed  $value  The value to cast
     * @param  ReflectionNamedType  $type  The named type
     * @return mixed The cast value
     */
    public static function castNamedType(mixed $value, ReflectionNamedType $type): mixed
    {
        $typeName = $type->getName();

        // Cast to DataModel
        if (is_subclass_of($typeName, DataModelContract::class) && is_array($value)) {
            return $typeName::fromArray($value);
        }

        // Cast to Enum
        if (enum_exists($typeName)) {
            if ($value instanceof $typeName) {
                return $value;
            }
            if (is_subclass_of($typeName, BackedEnum::class) && (is_string($value) || is_int($value))) {
                return $typeName::tryFrom($value) ?? $value;
            }
            if (is_subclass_of($typeName, UnitEnum::class) && (is_string($value) || is_int($value))) {
                foreach ($typeName::cases() as $case) {
                    if ($case->name === $value) {
                        return $case;
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Check if a value can potentially be cast to a given type
     *
     * Used for union type casting to determine if a type is worth trying.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value can potentially be cast to the type
     */
    public static function canCast(mixed $value, ReflectionType $type): bool
    {
        // Union types: let the recursive cast handle it
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
     * Check if a value is already valid for a given type
     *
     * Used for union type casting to determine if casting succeeded.
     *
     * @param  mixed  $value  The value to check
     * @param  ReflectionType  $type  The target type
     * @return bool True if the value is valid for the type
     */
    public static function isValidForType(mixed $value, ReflectionType $type): bool
    {
        // Union types should never appear nested
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
}
