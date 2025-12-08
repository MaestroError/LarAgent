<?php

/**
 * Quick validation script to verify UsesCachedReflection trait
 * works correctly with DataModel
 */

require_once __DIR__ . '/../vendor/autoload.php';

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

// Test fixtures from the original DataModel tests
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

class TestMultiTypeModel extends DataModel
{
    public string|int $flexible;
    
    public string|TestBackedEnum $mixed;
}

echo "=== DataModel with UsesCachedReflection Trait Validation ===\n\n";

try {
    // Test 1: Basic schema generation
    echo "Test 1: Basic schema generation\n";
    $schema = TestMainModel::generateSchema();
    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new Exception("Schema type is not object");
    }
    if (!isset($schema['properties']['name'])) {
        throw new Exception("Missing 'name' property in schema");
    }
    if (!isset($schema['properties']['age'])) {
        throw new Exception("Missing 'age' property in schema");
    }
    echo "✓ Basic schema generation works\n\n";

    // Test 2: Enum handling
    echo "Test 2: Enum handling in schema\n";
    $unitEnumSchema = $schema['properties']['unitEnum'];
    if (!isset($unitEnumSchema['enum']) || !in_array('OptionA', $unitEnumSchema['enum'])) {
        throw new Exception("Unit enum schema incorrect");
    }
    $backedEnumSchema = $schema['properties']['backedEnum'];
    if (!isset($backedEnumSchema['enum']) || !in_array('value_1', $backedEnumSchema['enum'])) {
        throw new Exception("Backed enum schema incorrect");
    }
    echo "✓ Enum handling works correctly\n\n";

    // Test 3: Nested DataModel
    echo "Test 3: Nested DataModel handling\n";
    $nestedSchema = $schema['properties']['nested'];
    if (!isset($nestedSchema['type']) || $nestedSchema['type'] !== 'object') {
        throw new Exception("Nested model schema type incorrect");
    }
    if (!isset($nestedSchema['properties']['nestedProp'])) {
        throw new Exception("Nested property missing");
    }
    echo "✓ Nested DataModel handling works\n\n";

    // Test 4: Union types (multitype support)
    echo "Test 4: Union type (multitype) handling\n";
    $multiTypeSchema = TestMultiTypeModel::generateSchema();
    $flexibleSchema = $multiTypeSchema['properties']['flexible'];
    if (!isset($flexibleSchema['oneOf'])) {
        throw new Exception("Union type should use oneOf: " . json_encode($flexibleSchema));
    }
    if (count($flexibleSchema['oneOf']) !== 2) {
        throw new Exception("Union type should have 2 options");
    }
    echo "✓ Union type handling works correctly\n";
    echo "   Schema: " . json_encode($flexibleSchema, JSON_PRETTY_PRINT) . "\n\n";

    // Test 5: Fill and toArray still work
    echo "Test 5: fill() and toArray() methods\n";
    $data = [
        'name' => 'John Doe',
        'age' => 30,
        'isActive' => false,
        'tags' => ['a', 'b'],
        'unitEnum' => 'OptionA',
        'backedEnum' => 'value_2',
        'nested' => [
            'nestedProp' => 'test value'
        ]
    ];
    
    $model = new TestMainModel();
    $model->fill($data);
    
    if ($model->name !== 'John Doe') {
        throw new Exception("fill() failed for name");
    }
    if ($model->age !== 30) {
        throw new Exception("fill() failed for age");
    }
    if ($model->unitEnum !== TestUnitEnum::OptionA) {
        throw new Exception("fill() failed for unit enum");
    }
    if ($model->backedEnum !== TestBackedEnum::Value2) {
        throw new Exception("fill() failed for backed enum");
    }
    
    $array = $model->toArray();
    if ($array['name'] !== 'John Doe') {
        throw new Exception("toArray() failed for name");
    }
    if ($array['unitEnum'] !== 'OptionA') {
        throw new Exception("toArray() failed for unit enum");
    }
    if ($array['backedEnum'] !== 'value_2') {
        throw new Exception("toArray() failed for backed enum");
    }
    
    echo "✓ fill() and toArray() work correctly\n\n";

    // Test 6: Caching
    echo "Test 6: Type reflection caching\n";
    // Generate schema multiple times to test caching
    for ($i = 0; $i < 100; $i++) {
        $s = TestMainModel::generateSchema();
    }
    echo "✓ Caching works without errors (generated schema 100 times)\n\n";

    echo "=== All Validation Tests Passed! ===\n";
    echo "\nThe UsesCachedReflection trait is working correctly with DataModel.\n";
    echo "The existing DataModel functionality is preserved.\n";
    exit(0);

} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
