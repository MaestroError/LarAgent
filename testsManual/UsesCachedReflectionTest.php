<?php

/**
 * Manual Test: UsesCachedReflection Trait
 * 
 * This test demonstrates how the UsesCachedReflection trait works
 * and how it provides cached reflection-based type resolution.
 * 
 * Run this test with: php testsManual/UsesCachedReflectionTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Traits\UsesCachedReflection;

// --- Test Fixtures ---

enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}

enum Status
{
    case Pending;
    case InProgress;
    case Completed;
    case Cancelled;
}

class Address extends DataModel
{
    public string $street;
    public string $city;
    public string $zipCode;
}

class Person extends DataModel
{
    public string $name;
    public int $age;
    public ?Address $address = null;
}

class Task extends DataModel
{
    public string $title;
    public Priority $priority;
    public Status $status;
}

// Class that uses the trait
class SchemaGenerator
{
    use UsesCachedReflection;

    public static function demonstrateTypeResolution(): void
    {
        echo "=== UsesCachedReflection Trait Demonstration ===\n\n";

        // 1. Builtin Types
        echo "1. Builtin Type Resolution:\n";
        echo "   - string: " . json_encode(self::builtinTypeToSchema('string')) . "\n";
        echo "   - int: " . json_encode(self::builtinTypeToSchema('int')) . "\n";
        echo "   - float: " . json_encode(self::builtinTypeToSchema('float')) . "\n";
        echo "   - bool: " . json_encode(self::builtinTypeToSchema('bool')) . "\n";
        echo "   - array: " . json_encode(self::builtinTypeToSchema('array')) . "\n\n";

        // 2. Enum Types
        echo "2. Enum Type Resolution:\n";
        $prioritySchema = self::enumTypeToSchema(Priority::class);
        echo "   - Priority (backed enum): " . json_encode($prioritySchema, JSON_PRETTY_PRINT) . "\n";
        $statusSchema = self::enumTypeToSchema(Status::class);
        echo "   - Status (unit enum): " . json_encode($statusSchema, JSON_PRETTY_PRINT) . "\n\n";

        // 3. DataModel Types
        echo "3. DataModel Type Resolution:\n";
        $addressSchema = self::dataModelTypeToSchema(Address::class);
        echo "   - Address: " . json_encode($addressSchema, JSON_PRETTY_PRINT) . "\n";
        $personSchema = self::dataModelTypeToSchema(Person::class);
        echo "   - Person: " . json_encode($personSchema, JSON_PRETTY_PRINT) . "\n\n";

        // 4. Union Types (using ReflectionType)
        echo "4. Union Type Resolution:\n";
        $reflection = new ReflectionParameter([function(string|int $param) {}, '__invoke'], 'param');
        $unionType = $reflection->getType();
        $unionSchema = self::reflectionTypeToSchema($unionType);
        echo "   - string|int: " . json_encode($unionSchema, JSON_PRETTY_PRINT) . "\n";

        $reflection2 = new ReflectionParameter([function(Priority|Status $param) {}, '__invoke'], 'param');
        $unionEnumType = $reflection2->getType();
        $unionEnumSchema = self::reflectionTypeToSchema($unionEnumType);
        echo "   - Priority|Status: " . json_encode($unionEnumSchema, JSON_PRETTY_PRINT) . "\n\n";

        // 5. Type Name to Schema (convenience method)
        echo "5. Type Name to Schema (String-based resolution):\n";
        echo "   - 'string': " . json_encode(self::typeNameToSchema('string')) . "\n";
        echo "   - 'int': " . json_encode(self::typeNameToSchema('int')) . "\n";
        echo "   - Priority::class: " . json_encode(self::typeNameToSchema(Priority::class)) . "\n";
        echo "   - Task::class: " . json_encode(self::typeNameToSchema(Task::class), JSON_PRETTY_PRINT) . "\n\n";
    }

    public static function demonstrateCaching(): void
    {
        echo "=== Caching Demonstration ===\n\n";

        // Clear cache first
        self::clearReflectionCache();
        echo "Cache cleared.\n\n";

        // Measure performance with caching
        echo "Resolving type 1000 times with caching:\n";
        $reflection = new ReflectionParameter([function(string $param) {}, '__invoke'], 'param');
        $type = $reflection->getType();

        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            self::namedTypeToSchema($type);
        }
        $withCache = microtime(true) - $start;
        echo "   Time: " . number_format($withCache * 1000, 4) . "ms\n\n";

        // Clear cache and measure again
        self::clearReflectionCache();
        echo "Cache cleared again.\n\n";

        echo "Resolving type 1000 times (rebuilding cache each time is slower):\n";
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            self::clearReflectionCache();
            self::namedTypeToSchema($type);
        }
        $withoutCache = microtime(true) - $start;
        echo "   Time: " . number_format($withoutCache * 1000, 4) . "ms\n\n";

        $improvement = (($withoutCache - $withCache) / $withoutCache) * 100;
        echo "Caching provides ~" . number_format($improvement, 1) . "% performance improvement!\n\n";
    }

    public static function demonstrateRealWorldUsage(): void
    {
        echo "=== Real-World Usage: Building Tool Schema ===\n\n";

        // Simulate building a tool schema like Agent does
        $methodReflection = new ReflectionMethod(self::class, 'exampleToolMethod');
        $parameters = $methodReflection->getParameters();

        echo "Tool: exampleToolMethod\n";
        echo "Parameters:\n";

        foreach ($parameters as $param) {
            $type = $param->getType();
            $schema = $type ? self::reflectionTypeToSchema($type) : ['type' => 'string'];
            
            echo "   - {$param->getName()}: " . json_encode($schema) . "\n";
        }
        
        echo "\nComplete Tool Schema:\n";
        $toolSchema = [
            'name' => 'exampleToolMethod',
            'description' => 'An example tool demonstrating type resolution',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ],
        ];

        foreach ($parameters as $param) {
            $type = $param->getType();
            $schema = $type ? self::reflectionTypeToSchema($type) : ['type' => 'string'];
            $toolSchema['parameters']['properties'][$param->getName()] = $schema;
            
            if (!$param->isOptional()) {
                $toolSchema['parameters']['required'][] = $param->getName();
            }
        }

        echo json_encode($toolSchema, JSON_PRETTY_PRINT) . "\n\n";
    }

    /**
     * Example tool method with various parameter types
     */
    public static function exampleToolMethod(
        string $title,
        Priority $priority,
        int $estimatedHours,
        Person $assignedTo,
        ?string $description = null,
        array $tags = []
    ): string {
        return "Tool executed";
    }
}

// --- Run the demonstrations ---

try {
    SchemaGenerator::demonstrateTypeResolution();
    SchemaGenerator::demonstrateCaching();
    SchemaGenerator::demonstrateRealWorldUsage();

    echo "=== All Tests Completed Successfully! ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
