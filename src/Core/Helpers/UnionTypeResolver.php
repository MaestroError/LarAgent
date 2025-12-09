<?php

namespace LarAgent\Core\Helpers;

use BackedEnum;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use UnitEnum;

/**
 * Helper class for resolving union types
 *
 * Provides centralized logic for matching and converting values to appropriate
 * types when dealing with union types (DataModel1|DataModel2|Enum1|Enum2).
 */
class UnionTypeResolver
{
    /**
     * Find the best matching DataModel class for an array value
     *
     * Compares input array keys against each DataModel's required properties
     * and returns the class with the highest match score.
     *
     * @param  array  $value  The input array
     * @param  array  $dataModelClasses  Array of DataModel class names
     * @return string|null The best matching class name, or null if no match
     */
    public static function findBestDataModelMatch(array $value, array $dataModelClasses): ?string
    {
        $bestMatch = null;
        $bestScore = -1;
        $inputKeys = array_keys($value);

        foreach ($dataModelClasses as $class) {
            if (! is_subclass_of($class, DataModelContract::class)) {
                continue;
            }

            try {
                // Get required properties from the DataModel's schema
                $schema = $class::generateSchema();
                $requiredProps = $schema['required'] ?? [];

                // Calculate match score: required properties present in input
                $matchCount = count(array_intersect($requiredProps, $inputKeys));

                // Also check if all required properties are present (for better accuracy)
                $allRequiredPresent = empty(array_diff($requiredProps, $inputKeys));

                // Prefer classes where all required properties are present
                if ($allRequiredPresent && $matchCount > $bestScore) {
                    $bestScore = $matchCount;
                    $bestMatch = $class;
                } elseif ($bestMatch === null && $matchCount > $bestScore) {
                    // Fallback: use partial match if no full match found
                    $bestScore = $matchCount;
                    $bestMatch = $class;
                }
            } catch (\Throwable $e) {
                // Skip invalid classes
                continue;
            }
        }

        return $bestMatch;
    }

    /**
     * Try to convert a value to one of the given enum classes
     *
     * @param  mixed  $value  The value to convert (should be string or int)
     * @param  array  $enumClasses  Array of enum class names
     * @return mixed The enum instance if found, or the original value
     */
    public static function tryConvertToEnum(mixed $value, array $enumClasses): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        foreach ($enumClasses as $enumClass) {
            if (! enum_exists($enumClass)) {
                continue;
            }

            // Try BackedEnum::tryFrom first
            if (is_subclass_of($enumClass, BackedEnum::class)) {
                $result = $enumClass::tryFrom($value);
                if ($result !== null) {
                    return $result;
                }
            } elseif (is_subclass_of($enumClass, UnitEnum::class)) {
                // For unit enums, match by name
                foreach ($enumClass::cases() as $case) {
                    if ($case->name === $value) {
                        return $case;
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Convert value to a DataModel instance from multiple possible classes
     *
     * @param  array  $value  The array value to convert
     * @param  array  $dataModelClasses  Array of DataModel class names
     * @return mixed The DataModel instance if successful, or the original value
     */
    public static function convertToDataModel(array $value, array $dataModelClasses): mixed
    {
        $bestMatch = static::findBestDataModelMatch($value, $dataModelClasses);

        if ($bestMatch !== null) {
            try {
                return $bestMatch::fromArray($value);
            } catch (\Throwable $e) {
                // Failed to create instance
            }
        }

        return $value;
    }

    /**
     * Convert value to either a DataModel or Enum from multiple possible classes
     *
     * Handles complex union types like DataModel1|DataModel2|Enum1|Enum2
     *
     * @param  mixed  $value  The value to convert
     * @param  array  $dataModelClasses  Array of DataModel class names
     * @param  array  $enumClasses  Array of enum class names
     * @return mixed The converted value or original if no conversion possible
     */
    public static function resolveUnionValue(mixed $value, array $dataModelClasses = [], array $enumClasses = []): mixed
    {
        // For array values, try DataModel conversion
        if (is_array($value) && ! empty($dataModelClasses)) {
            return static::convertToDataModel($value, $dataModelClasses);
        }

        // For scalar values, try enum conversion
        if ((is_string($value) || is_int($value)) && ! empty($enumClasses)) {
            return static::tryConvertToEnum($value, $enumClasses);
        }

        return $value;
    }
}
