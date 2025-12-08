<?php

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

// --- Fixtures ---

enum TestUnitEnum
{
    case OptionA;
    case OptionB;
}

enum TestBackedEnum: string
{
    case Value1 = 'value_1';
    case Value2 = 'value_2';
}

class TestNestedModel extends DataModel
{
    #[Desc('A nested property')]
    public string $nestedProp;
}

class TestMainModel extends DataModel
{
    #[Desc('The name')]
    public string $name;

    public int $age;

    public ?bool $isActive = true;

    public array $tags = [];

    public TestUnitEnum $unitEnum;

    public TestBackedEnum $backedEnum;

    public TestNestedModel $nested;
}

class TestMixedConstructorModel extends DataModel
{
    public string $derivedProperty;

    public function __construct(
        public string $promotedProp,
        string $normalArg
    ) {
        $this->derivedProperty = strtoupper($normalArg);
    }
}

// --- Tests ---

test('DataModel: fill and toArray work with scalars', function () {
    $data = [
        'name' => 'John Doe',
        'age' => 30,
        'isActive' => false,
        'tags' => ['a', 'b'],
    ];

    $model = new TestMainModel;
    $model->fill($data);

    expect($model->name)->toBe('John Doe');
    expect($model->age)->toBe(30);
    expect($model->isActive)->toBeFalse();
    expect($model->tags)->toBe(['a', 'b']);

    $array = $model->toArray();
    expect($array['name'])->toBe('John Doe');
    expect($array['age'])->toBe(30);
    expect($array['isActive'])->toBeFalse();
    expect($array['tags'])->toBe(['a', 'b']);
});

test('DataModel: fill and toArray work with Enums', function () {
    $data = [
        'unitEnum' => 'OptionA',
        'backedEnum' => 'value_2',
    ];

    $model = new TestMainModel;
    $model->fill($data);

    expect($model->unitEnum)->toBe(TestUnitEnum::OptionA);
    expect($model->backedEnum)->toBe(TestBackedEnum::Value2);

    $array = $model->toArray();
    expect($array['unitEnum'])->toBe('OptionA');
    expect($array['backedEnum'])->toBe('value_2');
});

test('DataModel: fill and toArray work with Nested DataModel', function () {
    $data = [
        'nested' => [
            'nestedProp' => 'nested value',
        ],
    ];

    $model = new TestMainModel;
    $model->fill($data);

    expect($model->nested)->toBeInstanceOf(TestNestedModel::class);
    expect($model->nested->nestedProp)->toBe('nested value');

    $array = $model->toArray();
    expect($array['nested'])->toBeArray();
    expect($array['nested']['nestedProp'])->toBe('nested value');
});

test('DataModel: fromArray static method works', function () {
    $data = [
        'name' => 'Jane',
        'age' => 25,
    ];

    $model = TestMainModel::fromArray($data);

    expect($model)->toBeInstanceOf(TestMainModel::class);
    expect($model->name)->toBe('Jane');
    expect($model->age)->toBe(25);
});

test('DataModel: ArrayAccess works with fill logic', function () {
    $model = new TestMainModel;

    // Offset Set
    $model['name'] = 'Alice';
    $model['nested'] = ['nestedProp' => 'deep value']; // Should trigger fill and cast to TestNestedModel

    expect($model->name)->toBe('Alice');
    expect($model->nested)->toBeInstanceOf(TestNestedModel::class);
    expect($model->nested->nestedProp)->toBe('deep value');

    // Offset Get
    expect($model['name'])->toBe('Alice');
    expect($model['nested'])->toBeInstanceOf(TestNestedModel::class);

    // Offset Exists
    expect(isset($model['name']))->toBeTrue();
    expect(isset($model['nonExistent']))->toBeFalse();

    // Offset Unset
    unset($model['name']);
    expect(isset($model['name']))->toBeFalse();
});

test('DataModel: toSchema generates correct OpenAPI schema', function () {
    $model = new TestMainModel;
    $schema = $model->toSchema();

    expect($schema['type'])->toBe('object');

    // Check required fields
    // name, age, unitEnum, backedEnum, nested are required (no default, not nullable)
    // isActive has default, tags has default (implied empty array? No, explicit default in class)
    // Wait, in TestMainModel:
    // public string $name; -> Required
    // public int $age; -> Required
    // public ?bool $isActive = true; -> Not required (has default)
    // public array $tags = []; -> Not required (has default)
    // public TestUnitEnum $unitEnum; -> Required
    // public TestBackedEnum $backedEnum; -> Required
    // public TestNestedModel $nested; -> Required

    expect($schema['required'])->toContain('name', 'age', 'unitEnum', 'backedEnum', 'nested');
    expect($schema['required'])->not->toContain('isActive', 'tags');

    // Check properties
    $props = $schema['properties'];

    // String with Desc
    expect($props['name']['type'])->toBe('string');
    expect($props['name']['description'])->toBe('The name');

    // Int
    expect($props['age']['type'])->toBe('integer');

    // Enum (Unit)
    expect($props['unitEnum']['type'])->toBe('string');
    expect($props['unitEnum']['enum'])->toBe(['OptionA', 'OptionB']);

    // Enum (Backed)
    expect($props['backedEnum']['type'])->toBe('string');
    expect($props['backedEnum']['enum'])->toBe(['value_1', 'value_2']);

    // Nested Model
    expect($props['nested']['type'])->toBe('object');
    expect($props['nested']['properties']['nestedProp']['type'])->toBe('string');
    expect($props['nested']['properties']['nestedProp']['description'])->toBe('A nested property');
});

test('DataModel: fill ignores unknown properties', function () {
    $data = [
        'name' => 'John',
        'unknown_prop' => 'should be ignored',
    ];

    $model = new TestMainModel;
    $model->fill($data);

    expect($model->name)->toBe('John');
    expect(property_exists($model, 'unknown_prop'))->toBeFalse();
    // Also check it wasn't added dynamically (though typed properties prevent this usually, but stdClass would allow it. DataModel is not stdClass)
    expect(isset($model->unknown_prop))->toBeFalse();
});

test('DataModel: fromArray works with mixed constructor arguments', function () {
    $data = [
        'promotedProp' => 'foo',
        'normalArg' => 'bar',
    ];

    $model = TestMixedConstructorModel::fromArray($data);

    expect($model->promotedProp)->toBe('foo');
    expect($model->derivedProperty)->toBe('BAR');
});

test('DataModel: implements JsonSerializable', function () {
    $data = [
        'name' => 'John JSON',
        'age' => 40,
    ];

    $model = new TestMainModel;
    $model->fill($data);

    $json = json_encode($model);
    $decoded = json_decode($json, true);

    expect($decoded['name'])->toBe('John JSON');
    expect($decoded['age'])->toBe(40);
});

// --- Multi-Type Support Tests ---

enum TestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

class TestMultiTypeModel extends DataModel
{
    // Single type (baseline test)
    public string $singleType;

    // Primitive union types
    public string|int $stringOrInt;

    // Union with nullable
    public string|int|null $stringOrIntNullable = null;

    // Union with enum
    public string|TestBackedEnum $stringOrEnum;

    // Union with DataModel
    public string|TestNestedModel $stringOrModel;

    // Complex union: primitive + enum + DataModel
    public string|int|TestBackedEnum|TestNestedModel $complexUnion;
}

test('DataModel: toSchema generates correct schema for single type (baseline)', function () {
    $schema = TestMultiTypeModel::generateSchema();

    expect($schema['properties']['singleType'])->toBe(['type' => 'string']);
});

test('DataModel: toSchema generates oneOf for primitive union types', function () {
    $schema = TestMultiTypeModel::generateSchema();

    expect($schema['properties']['stringOrInt'])->toHaveKey('oneOf');
    expect($schema['properties']['stringOrInt']['oneOf'])->toHaveCount(2);
    expect($schema['properties']['stringOrInt']['oneOf'][0])->toBe(['type' => 'string']);
    expect($schema['properties']['stringOrInt']['oneOf'][1])->toBe(['type' => 'integer']);
});

test('DataModel: toSchema handles nullable union types correctly', function () {
    $schema = TestMultiTypeModel::generateSchema();

    // Nullable union should still generate oneOf but without null type
    expect($schema['properties']['stringOrIntNullable'])->toHaveKey('oneOf');
    expect($schema['properties']['stringOrIntNullable']['oneOf'])->toHaveCount(2);

    // Should not be in required array since it's nullable
    expect($schema['required'])->not->toContain('stringOrIntNullable');
});

test('DataModel: toSchema generates oneOf for union with enum', function () {
    $schema = TestMultiTypeModel::generateSchema();

    expect($schema['properties']['stringOrEnum'])->toHaveKey('oneOf');
    expect($schema['properties']['stringOrEnum']['oneOf'])->toHaveCount(2);

    // First type should be enum with values
    expect($schema['properties']['stringOrEnum']['oneOf'][0])->toBe(['type' => 'string', 'enum' => ['value_1', 'value_2']]);

    // Second type should be plain string
    expect($schema['properties']['stringOrEnum']['oneOf'][1])->toBe(['type' => 'string']);
});

test('DataModel: toSchema generates oneOf for union with DataModel', function () {
    $schema = TestMultiTypeModel::generateSchema();

    expect($schema['properties']['stringOrModel'])->toHaveKey('oneOf');
    expect($schema['properties']['stringOrModel']['oneOf'])->toHaveCount(2);

    // First type should be nested object
    expect($schema['properties']['stringOrModel']['oneOf'][0]['type'])->toBe('object');
    expect($schema['properties']['stringOrModel']['oneOf'][0]['properties'])->toHaveKey('nestedProp');

    // Second type should be plain string
    expect($schema['properties']['stringOrModel']['oneOf'][1])->toBe(['type' => 'string']);
});

test('DataModel: toSchema generates oneOf for complex union types', function () {
    $schema = TestMultiTypeModel::generateSchema();

    expect($schema['properties']['complexUnion'])->toHaveKey('oneOf');
    expect($schema['properties']['complexUnion']['oneOf'])->toHaveCount(4);

    // Check all type combinations are present
    $types = array_column($schema['properties']['complexUnion']['oneOf'], 'type');
    expect($types)->toContain('string', 'integer', 'object');
});

test('DataModel: fill and casting work with union types - primitive values', function () {
    $model = new TestMultiTypeModel;

    // Test string value
    $model->fill(['stringOrInt' => 'hello']);
    expect($model->stringOrInt)->toBe('hello');

    // Test int value
    $model->fill(['stringOrInt' => 42]);
    expect($model->stringOrInt)->toBe(42);
});

test('DataModel: fill and casting work with union types - enum values', function () {
    $model = new TestMultiTypeModel;

    // Test casting string to enum
    $model->fill(['stringOrEnum' => 'value_1']);
    expect($model->stringOrEnum)->toBe(TestBackedEnum::Value1);

    // Test keeping string as string
    $model->fill(['stringOrEnum' => 'not_an_enum_value']);
    expect($model->stringOrEnum)->toBe('not_an_enum_value');
});

test('DataModel: fill and casting work with union types - DataModel values', function () {
    $model = new TestMultiTypeModel;

    // Test casting array to DataModel
    $model->fill(['stringOrModel' => ['nestedProp' => 'test value']]);
    expect($model->stringOrModel)->toBeInstanceOf(TestNestedModel::class);
    expect($model->stringOrModel->nestedProp)->toBe('test value');

    // Test keeping string as string
    $model->fill(['stringOrModel' => 'plain string']);
    expect($model->stringOrModel)->toBe('plain string');
});

test('DataModel: fromArray works with multi-type properties', function () {
    $data = [
        'singleType' => 'test',
        'stringOrInt' => 123,
        'stringOrEnum' => 'value_2',
        'stringOrModel' => ['nestedProp' => 'nested'],
        'complexUnion' => 'complex string',
    ];

    $model = TestMultiTypeModel::fromArray($data);

    expect($model->singleType)->toBe('test');
    expect($model->stringOrInt)->toBe(123);
    expect($model->stringOrEnum)->toBe(TestBackedEnum::Value2);
    expect($model->stringOrModel)->toBeInstanceOf(TestNestedModel::class);
    expect($model->complexUnion)->toBe('complex string');
});

test('DataModel: toArray works correctly with multi-type properties', function () {
    $model = new TestMultiTypeModel;
    $model->fill([
        'singleType' => 'test',
        'stringOrInt' => 42,
        'stringOrEnum' => 'value_1',
        'stringOrModel' => ['nestedProp' => 'test'],
        'complexUnion' => 'value_2',
    ]);

    $array = $model->toArray();

    expect($array['singleType'])->toBe('test');
    expect($array['stringOrInt'])->toBe(42);
    expect($array['stringOrEnum'])->toBe('value_1'); // Enum serialized to value
    expect($model->stringOrEnum)->toBe(TestBackedEnum::Value1);
    expect($array['stringOrModel'])->toBeArray();
    expect($array['stringOrModel']['nestedProp'])->toBe('test');
    expect($array['complexUnion'])->toBe('value_2');
});
