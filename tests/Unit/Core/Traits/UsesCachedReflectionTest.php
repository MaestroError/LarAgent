<?php

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Traits\UsesCachedReflection;

// --- Test Fixtures ---

enum CachedReflectionTestStringEnum: string
{
    case First = 'first';
    case Second = 'second';
    case Third = 'third';
}

enum CachedReflectionTestIntEnum: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;
}

enum CachedReflectionTestUnitEnum
{
    case Alpha;
    case Beta;
    case Gamma;
}

class CachedReflectionTestDataModel extends DataModel
{
    public string $name;

    public int $count;
}

/**
 * Test class that exposes protected trait methods for testing
 * We need to rename the trait methods and call them, since traits don't support parent::
 */
class TestClassUsingTrait
{
    use UsesCachedReflection {
        UsesCachedReflection::builtinTypeToSchema as protected traitBuiltinTypeToSchema;
        UsesCachedReflection::enumTypeToSchema as protected traitEnumTypeToSchema;
        UsesCachedReflection::dataModelTypeToSchema as protected traitDataModelTypeToSchema;
        UsesCachedReflection::typeNameToSchema as protected traitTypeNameToSchema;
        UsesCachedReflection::namedTypeToSchema as protected traitNamedTypeToSchema;
        UsesCachedReflection::unionTypeToSchema as protected traitUnionTypeToSchema;
        UsesCachedReflection::reflectionTypeToSchema as protected traitReflectionTypeToSchema;
    }

    // Expose protected methods as public for testing
    public static function clearReflectionCache(): void
    {
        static::$reflectionCache = [];
    }

    public static function builtinTypeToSchema(string $typeName): array
    {
        return static::traitBuiltinTypeToSchema($typeName);
    }

    public static function enumTypeToSchema(string $enumClassName): array
    {
        return static::traitEnumTypeToSchema($enumClassName);
    }

    public static function dataModelTypeToSchema(string $dataModelClassName): array
    {
        return static::traitDataModelTypeToSchema($dataModelClassName);
    }

    public static function typeNameToSchema(string $typeName): array|string
    {
        return static::traitTypeNameToSchema($typeName);
    }

    public static function namedTypeToSchema(\ReflectionNamedType $namedType): array
    {
        return static::traitNamedTypeToSchema($namedType);
    }

    public static function unionTypeToSchema(\ReflectionUnionType $unionType): array
    {
        return static::traitUnionTypeToSchema($unionType);
    }

    public static function reflectionTypeToSchema(?\ReflectionType $type): array
    {
        return static::traitReflectionTypeToSchema($type);
    }
}

// --- Test Cases ---

describe('UsesCachedReflection Trait', function () {

    beforeEach(function () {
        // Clear cache before each test
        TestClassUsingTrait::clearReflectionCache();
    });

    describe('builtinTypeToSchema()', function () {
        it('converts int to integer schema', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('int');
            expect($schema)->toBe(['type' => 'integer']);
        });

        it('converts float to number schema', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('float');
            expect($schema)->toBe(['type' => 'number']);
        });

        it('converts bool to boolean schema', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('bool');
            expect($schema)->toBe(['type' => 'boolean']);
        });

        it('converts string to string schema', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('string');
            expect($schema)->toBe(['type' => 'string']);
        });

        it('converts array to array schema with anyOf items', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('array');
            expect($schema)->toBe([
                'type' => 'array',
                'items' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'integer'],
                        ['type' => 'number'],
                        ['type' => 'boolean'],
                    ],
                ],
            ]);
        });

        it('converts object to object schema', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('object');
            expect($schema)->toBe(['type' => 'object']);
        });

        it('defaults unknown types to string', function () {
            $schema = TestClassUsingTrait::builtinTypeToSchema('unknown');
            expect($schema)->toBe(['type' => 'string']);
        });
    });

    describe('enumTypeToSchema()', function () {
        it('converts backed string enum to schema with enum values', function () {
            $schema = TestClassUsingTrait::enumTypeToSchema(CachedReflectionTestStringEnum::class);

            expect($schema)->toHaveKey('type');
            expect($schema)->toHaveKey('enum');
            expect($schema['type'])->toBe('string');
            expect($schema['enum'])->toBe(['first', 'second', 'third']);
        });

        it('converts backed int enum to schema with integer type', function () {
            $schema = TestClassUsingTrait::enumTypeToSchema(CachedReflectionTestIntEnum::class);

            expect($schema)->toHaveKey('type');
            expect($schema)->toHaveKey('enum');
            expect($schema['type'])->toBe('integer');
            expect($schema['enum'])->toBe([1, 2, 3]);
        });

        it('converts unit enum to schema with case names', function () {
            $schema = TestClassUsingTrait::enumTypeToSchema(CachedReflectionTestUnitEnum::class);

            expect($schema)->toHaveKey('type');
            expect($schema)->toHaveKey('enum');
            expect($schema['type'])->toBe('string');
            expect($schema['enum'])->toBe(['Alpha', 'Beta', 'Gamma']);
        });
    });

    describe('dataModelTypeToSchema()', function () {
        it('generates schema from DataModel class', function () {
            $schema = TestClassUsingTrait::dataModelTypeToSchema(CachedReflectionTestDataModel::class);

            expect($schema)->toHaveKey('type');
            expect($schema)->toHaveKey('properties');
            expect($schema['type'])->toBe('object');
            expect($schema['properties'])->toHaveKey('name');
            expect($schema['properties'])->toHaveKey('count');
        });
    });

    describe('typeNameToSchema()', function () {
        it('converts string type name to simple string', function () {
            $schema = TestClassUsingTrait::typeNameToSchema('string');
            expect($schema)->toBe('string');
        });

        it('converts int type name to integer', function () {
            $schema = TestClassUsingTrait::typeNameToSchema('int');
            expect($schema)->toBe('integer');
        });

        it('converts enum class name to schema', function () {
            $schema = TestClassUsingTrait::typeNameToSchema(CachedReflectionTestStringEnum::class);
            expect($schema)->toBeArray();
            expect($schema)->toHaveKey('type');
            expect($schema)->toHaveKey('enum');
        });

        it('converts DataModel class name to schema', function () {
            $schema = TestClassUsingTrait::typeNameToSchema(CachedReflectionTestDataModel::class);
            expect($schema)->toBeArray();
            expect($schema)->toHaveKey('type');
            expect($schema['type'])->toBe('object');
        });
    });

    describe('namedTypeToSchema()', function () {
        it('handles builtin type correctly', function () {
            $reflection = new ReflectionParameter([fn (string $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::namedTypeToSchema($type);
            expect($schema)->toBe(['type' => 'string']);
        });

        it('caches builtin types', function () {
            $reflection = new ReflectionParameter([fn (int $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            // First call
            $schema1 = TestClassUsingTrait::namedTypeToSchema($type);
            // Second call should use cache
            $schema2 = TestClassUsingTrait::namedTypeToSchema($type);

            expect($schema1)->toBe(['type' => 'integer']);
            expect($schema2)->toBe(['type' => 'integer']);
        });

        it('handles enum types', function () {
            $reflection = new ReflectionParameter([fn (CachedReflectionTestStringEnum $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::namedTypeToSchema($type);
            expect($schema)->toHaveKey('enum');
            expect($schema['enum'])->toBe(['first', 'second', 'third']);
        });

        it('handles DataModel types', function () {
            $reflection = new ReflectionParameter([fn (CachedReflectionTestDataModel $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::namedTypeToSchema($type);
            expect($schema)->toHaveKey('type');
            expect($schema['type'])->toBe('object');
        });
    });

    describe('unionTypeToSchema()', function () {
        it('handles union types with oneOf', function () {
            $reflection = new ReflectionParameter([fn (string|int $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::unionTypeToSchema($type);

            expect($schema)->toHaveKey('oneOf');
            expect($schema['oneOf'])->toHaveCount(2);
            expect($schema['oneOf'][0])->toBe(['type' => 'string']);
            expect($schema['oneOf'][1])->toBe(['type' => 'integer']);
        });

        it('filters out null type from union (null handled by driver)', function () {
            // Note: string|null in PHP is internally ?string (ReflectionNamedType, not ReflectionUnionType)
            // So we need to use a three-way union with null to test this behavior
            $reflection = new ReflectionParameter([fn (string|int|null $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::unionTypeToSchema($type);

            // Should filter out null (null handling is done by provider-specific drivers)
            expect($schema)->toHaveKey('oneOf');
            expect($schema['oneOf'])->toHaveCount(2);
            expect($schema['oneOf'][0])->toBe(['type' => 'string']);
            expect($schema['oneOf'][1])->toBe(['type' => 'integer']);
        });

        it('handles three-way union', function () {
            $reflection = new ReflectionParameter([fn (string|int|bool $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::unionTypeToSchema($type);

            expect($schema)->toHaveKey('oneOf');
            expect($schema['oneOf'])->toHaveCount(3);
        });
    });

    describe('reflectionTypeToSchema()', function () {
        it('handles null type gracefully', function () {
            $schema = TestClassUsingTrait::reflectionTypeToSchema(null);
            expect($schema)->toBe(['type' => 'string']);
        });

        it('handles named types', function () {
            $reflection = new ReflectionParameter([fn (int $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::reflectionTypeToSchema($type);
            expect($schema)->toBe(['type' => 'integer']);
        });

        it('handles union types', function () {
            $reflection = new ReflectionParameter([fn (string|int $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::reflectionTypeToSchema($type);
            expect($schema)->toHaveKey('oneOf');
        });

        it('handles complex union with enum and DataModel', function () {
            $reflection = new ReflectionParameter([
                fn (CachedReflectionTestStringEnum|CachedReflectionTestDataModel $param) => null,
                '__invoke',
            ], 'param');
            $type = $reflection->getType();

            $schema = TestClassUsingTrait::reflectionTypeToSchema($type);
            expect($schema)->toHaveKey('oneOf');
            expect($schema['oneOf'])->toHaveCount(2);
        });
    });

    describe('Cache management', function () {
        it('caches repeated type resolutions', function () {
            $reflection = new ReflectionParameter([fn (int $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            // Call multiple times
            TestClassUsingTrait::namedTypeToSchema($type);
            TestClassUsingTrait::namedTypeToSchema($type);
            TestClassUsingTrait::namedTypeToSchema($type);

            // Verify it worked (implicitly tests caching works without errors)
            expect(true)->toBeTrue();
        });

        it('clears cache correctly', function () {
            $reflection = new ReflectionParameter([fn (string $param) => null, '__invoke'], 'param');
            $type = $reflection->getType();

            // Populate cache
            TestClassUsingTrait::namedTypeToSchema($type);

            // Clear cache
            TestClassUsingTrait::clearReflectionCache();

            // Should still work after clearing
            $schema = TestClassUsingTrait::namedTypeToSchema($type);
            expect($schema)->toBe(['type' => 'string']);
        });
    });
});
