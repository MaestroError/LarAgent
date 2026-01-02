<?php

namespace LarAgent\Core\Helpers;

use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Extracts type metadata from ReflectionType for union type handling
 */
class TypeInfoExtractor
{
    /**
     * Get all class names from a type that are subclasses of a specific class
     */
    public static function getClassesOfType(?ReflectionType $type, string $parentClass): array
    {
        if ($type === null) {
            return [];
        }

        $classes = [];

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $typeName = $type->getName();
            if (is_subclass_of($typeName, $parentClass)) {
                $classes[] = $typeName;
            }
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                $classes = array_merge($classes, static::getClassesOfType($subType, $parentClass));
            }
        }

        return $classes;
    }

    /**
     * Get all enum class names from a type
     */
    public static function getEnumClasses(?ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        $enums = [];

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $typeName = $type->getName();
            if (enum_exists($typeName)) {
                $enums[] = $typeName;
            }
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                $enums = array_merge($enums, static::getEnumClasses($subType));
            }
        }

        return $enums;
    }
}
