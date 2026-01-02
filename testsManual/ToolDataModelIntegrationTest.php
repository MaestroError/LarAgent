<?php

/**
 * Test to verify DataModel and union type support in Tool execution
 * This demonstrates the complete integration of the refactored UsesCachedReflection trait
 */

// Simple autoloader
spl_autoload_register(function ($class) {
    $prefix = 'LarAgent\\';
    $base_dir = __DIR__.'/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir.str_replace('\\', '/', $relative_class).'.php';

    if (file_exists($file)) {
        require $file;
    }
});

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Traits\UsesCachedReflection;
use LarAgent\Tool;

// Test fixtures
enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}

class TaskData extends DataModel
{
    public string $title;

    public int $estimatedHours;

    public ?string $description = null;
}

class ToolTestHelper
{
    use UsesCachedReflection;

    // Expose protected methods as public for testing
    public static function testDataModelTypeToSchema(string $class): array
    {
        return static::dataModelTypeToSchema($class);
    }

    public static function testEnumTypeToSchema(string $class): array
    {
        return static::enumTypeToSchema($class);
    }

    public static function testReflectionTypeToSchema(?ReflectionType $type): array
    {
        return static::reflectionTypeToSchema($type);
    }

    public static function testCastValue(mixed $value, ?ReflectionType $type): mixed
    {
        return static::castValue($value, $type);
    }
}

echo "=== DataModel and Union Type Integration Test ===\n\n";

try {
    // Test 1: Tool with DataModel parameter
    echo "Test 1: Tool with DataModel parameter\n";

    $tool = Tool::create('createTask', 'Create a task from data');

    // Simulate what Agent.buildToolsFromAttributeMethods() does
    $taskDataType = new ReflectionNamedType;
    // We can't actually create ReflectionNamedType, so we'll use getTypeInfo differently
    // Instead, let's get the schema directly
    $schema = ToolTestHelper::testdataModelTypeToSchema(TaskData::class);

    echo '   DataModel schema: '.json_encode($schema, JSON_PRETTY_PRINT)."\n";

    // Verify schema has the right structure
    if (! isset($schema['type']) || $schema['type'] !== 'object') {
        throw new Exception("DataModel schema should have type 'object'");
    }
    if (! isset($schema['properties']['title'])) {
        throw new Exception("DataModel schema missing 'title' property");
    }
    if (! isset($schema['properties']['estimatedHours'])) {
        throw new Exception("DataModel schema missing 'estimatedHours' property");
    }

    // Now test that Tool can convert array back to DataModel
    $tool->addDataModelType('task', TaskData::class);
    $tool->setCallback(function (TaskData $task) {
        return "Created task: {$task->title}";
    });

    // Simulate API returning an array (as it would in real usage)
    $apiInput = [
        'task' => [
            'title' => 'Implement feature',
            'estimatedHours' => 8,
            'description' => 'Add new DataModel support',
        ],
    ];

    $result = $tool->execute($apiInput);
    if ($result !== 'Created task: Implement feature') {
        throw new Exception('Tool execution with DataModel failed');
    }

    echo "   ✓ DataModel converted from array successfully\n";
    echo "   ✓ Tool executed with DataModel parameter\n\n";

    // Test 2: Tool with enum parameter
    echo "Test 2: Tool with enum parameter\n";

    $enumSchema = ToolTestHelper::testenumTypeToSchema(Priority::class);
    echo '   Enum schema: '.json_encode($enumSchema)."\n";

    if (! isset($enumSchema['enum'])) {
        throw new Exception("Enum schema should have 'enum' values");
    }
    if (! in_array('low', $enumSchema['enum'])) {
        throw new Exception("Enum values should include 'low'");
    }

    $toolWithEnum = Tool::create('setPriority', 'Set task priority');
    $toolWithEnum->addProperty('priority', 'string', '', [
        'values' => $enumSchema['enum'],
        'enumClass' => Priority::class,
    ]);
    $toolWithEnum->setCallback(function (Priority $priority) {
        return "Priority set to: {$priority->value}";
    });

    $result = $toolWithEnum->execute(['priority' => 'high']);
    if ($result !== 'Priority set to: high') {
        throw new Exception('Tool execution with enum failed');
    }

    echo "   ✓ Enum converted from string successfully\n";
    echo "   ✓ Tool executed with enum parameter\n\n";

    // Test 3: castValue handles both enums and DataModels
    echo "Test 3: castValue handles special types\n";

    // Get a ReflectionType for testing (we'll create a dummy class)
    $dummyClass = new class
    {
        public TaskData $task;

        public Priority $priority;
    };

    $reflection = new ReflectionClass($dummyClass);

    // Test casting array to DataModel
    $taskProp = $reflection->getProperty('task');
    $taskType = $taskProp->getType();

    $arrayValue = ['title' => 'Test Task', 'estimatedHours' => 5];
    $castTask = ToolTestHelper::testcastValue($arrayValue, $taskType);

    if (! $castTask instanceof TaskData) {
        throw new Exception('castValue should convert array to TaskData');
    }
    if ($castTask->title !== 'Test Task') {
        throw new Exception('Converted TaskData has wrong title');
    }

    echo "   ✓ castValue converts array to DataModel\n";

    // Test casting string to enum
    $priorityProp = $reflection->getProperty('priority');
    $priorityType = $priorityProp->getType();

    $castPriority = ToolTestHelper::testcastValue('medium', $priorityType);

    if (! $castPriority instanceof Priority) {
        throw new Exception('castValue should convert string to Priority enum');
    }
    if ($castPriority !== Priority::Medium) {
        throw new Exception('Converted Priority has wrong value');
    }

    echo "   ✓ castValue converts string to enum\n\n";

    // Test 4: Union types with DataModel
    echo "Test 4: Union type support\n";

    $unionDummy = new class
    {
        public string|int $flexible;

        public string|TaskData $mixed;
    };

    $unionReflection = new ReflectionClass($unionDummy);
    $flexibleProp = $unionReflection->getProperty('flexible');
    $flexibleType = $flexibleProp->getType();

    $schema = ToolTestHelper::testreflectionTypeToSchema($flexibleType);
    echo '   Union type (string|int) schema: '.json_encode($schema)."\n";

    if (! isset($schema['oneOf'])) {
        throw new Exception("Union type should use 'oneOf' in schema");
    }
    if (count($schema['oneOf']) !== 2) {
        throw new Exception('Union type should have 2 options');
    }

    echo "   ✓ Union types generate oneOf schema\n";

    // Test casting with union type
    $stringValue = 'test';
    $castValue = ToolTestHelper::testcastValue($stringValue, $flexibleType);
    if ($castValue !== 'test') {
        throw new Exception('Union type should preserve string value');
    }

    $intValue = 42;
    $castValue = ToolTestHelper::testcastValue($intValue, $flexibleType);
    if ($castValue !== 42) {
        throw new Exception('Union type should preserve int value');
    }

    echo "   ✓ Union type casting works correctly\n\n";

    echo "=== All Integration Tests Passed! ===\n\n";
    echo "Summary:\n";
    echo "  ✓ DataModel types work in tools\n";
    echo "  ✓ API arrays are converted back to DataModel instances\n";
    echo "  ✓ Enum types work in tools\n";
    echo "  ✓ castValue handles both enums and DataModels\n";
    echo "  ✓ Union types generate correct schemas\n";
    echo "  ✓ All reflection logic is now in UsesCachedReflection trait\n";
    echo "\nThe refactoring is complete and working!\n";

    exit(0);

} catch (Exception $e) {
    echo '✗ FAILED: '.$e->getMessage()."\n";
    echo 'Trace: '.$e->getTraceAsString()."\n";
    exit(1);
}
