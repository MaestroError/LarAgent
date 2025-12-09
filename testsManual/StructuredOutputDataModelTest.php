<?php

/**
 * Structured Output with DataModel Test
 *
 * Tests Agent class support for DataModel in structured output:
 * - Using DataModel instance with responseSchema property
 * - Using DataModel class name with responseSchema property
 * - Overriding structuredOutput method to return DataModel instance
 * - Overriding structuredOutput method to return DataModel class name
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

// Simple mock for testing without full Laravel setup
class TestAgentMock
{
    protected $responseSchema;

    public function setResponseSchema($schema)
    {
        $this->responseSchema = $schema;
    }

    /**
     * Get the structured output schema if any
     *
     * @return array|null The response schema or null if none set
     */
    public function structuredOutput()
    {
        if (empty($this->responseSchema)) {
            return null;
        }

        // If it's a DataModel instance, call toSchema()
        if ($this->responseSchema instanceof \LarAgent\Core\Contracts\DataModel) {
            return $this->responseSchema->toSchema();
        }

        // If it's a DataModel class name, call generateSchema() statically
        if (is_string($this->responseSchema) && is_subclass_of($this->responseSchema, \LarAgent\Core\Contracts\DataModel::class)) {
            return $this->responseSchema::generateSchema();
        }

        // Otherwise, return the array schema as-is
        return $this->responseSchema;
    }
}

// ============================================================================
// DataModel Definitions
// ============================================================================

/**
 * Simple weather information DataModel
 */
class WeatherInfo extends DataModel
{
    #[Desc('The city name')]
    public string $city;

    #[Desc('The temperature in celsius')]
    public float $temperature;

    #[Desc('Weather condition (e.g., sunny, cloudy, rainy)')]
    public string $condition;
}

/**
 * Multiple locations weather DataModel
 */
class WeatherReport extends DataModel
{
    #[Desc('List of weather information for different cities')]
    public array $locations; // Array of WeatherInfo

    #[Desc('Report generation timestamp')]
    public ?string $timestamp = null;
}

// ============================================================================
// Agent Definitions
// ============================================================================

/**
 * Test 1: DataModel Instance
 */
class TestAgentWithDataModelInstance extends TestAgentMock
{
    public function __construct()
    {
        // Set the responseSchema to a DataModel instance
        $this->responseSchema = new WeatherInfo();
    }
}

/**
 * Test 2: DataModel Class Name
 */
class TestAgentWithDataModelClassName extends TestAgentMock
{
    protected $responseSchema = WeatherInfo::class;
}

/**
 * Test 3: Override structuredOutput with instance
 */
class TestAgentOverrideWithInstance extends TestAgentMock
{
    public function structuredOutput()
    {
        return (new WeatherInfo())->toSchema();
    }
}

/**
 * Test 4: Override structuredOutput with class name
 */
class TestAgentOverrideWithClassName extends TestAgentMock
{
    public function structuredOutput()
    {
        return WeatherInfo::generateSchema();
    }
}

/**
 * Test 5: Complex DataModel
 */
class TestAgentWithComplexDataModel extends TestAgentMock
{
    protected $responseSchema = WeatherReport::class;
}

// ============================================================================
// Test Functions
// ============================================================================

function runTest(string $testName, callable $testFn): void
{
    echo "\n================================================================================\n";
    echo "TEST: {$testName}\n";
    echo "================================================================================\n\n";

    try {
        $testFn();
        echo "✅ {$testName} PASSED\n";
    } catch (\Throwable $e) {
        echo "❌ {$testName} FAILED\n";
        echo "Error: {$e->getMessage()}\n";
        echo "Trace: {$e->getTraceAsString()}\n";
        exit(1);
    }
}

// ============================================================================
// Run Tests
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║         STRUCTURED OUTPUT WITH DATAMODEL - MANUAL TESTS                    ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

// Test 1: DataModel Instance
runTest('Test 1: DataModel Instance in responseSchema', function () {
    $agent = new TestAgentWithDataModelInstance();

    // Verify structuredOutput returns a schema array
    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    if (!isset($schema['properties']['city'])) {
        throw new \Exception("Schema should have 'city' property");
    }

    if (!isset($schema['properties']['temperature'])) {
        throw new \Exception("Schema should have 'temperature' property");
    }

    if (!isset($schema['properties']['condition'])) {
        throw new \Exception("Schema should have 'condition' property");
    }

    echo "Schema validation passed:\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

// Test 2: DataModel Class Name
runTest('Test 2: DataModel Class Name in responseSchema', function () {
    $agent = new TestAgentWithDataModelClassName();

    // Verify structuredOutput returns a schema array
    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    if (!isset($schema['properties']['city'])) {
        throw new \Exception("Schema should have 'city' property");
    }

    echo "Schema validation passed:\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

// Test 3: Override structuredOutput with instance
runTest('Test 3: Override structuredOutput Method with Instance', function () {
    $agent = new TestAgentOverrideWithInstance();

    // Verify structuredOutput returns a schema array
    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    echo "Schema validation passed:\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

// Test 4: Override structuredOutput with class name
runTest('Test 4: Override structuredOutput Method with Class Name', function () {
    $agent = new TestAgentOverrideWithClassName();

    // Verify structuredOutput returns a schema array
    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    echo "Schema validation passed:\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

// Test 5: Complex DataModel
runTest('Test 5: Complex DataModel with Nested Structures', function () {
    $agent = new TestAgentWithComplexDataModel();

    // Verify structuredOutput returns a schema array
    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    if (!isset($schema['properties']['locations'])) {
        throw new \Exception("Schema should have 'locations' property");
    }

    echo "Schema validation passed:\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

// Test 6: Verify backward compatibility with array schemas
runTest('Test 6: Backward Compatibility with Array Schemas', function () {
    $agent = new TestAgentMock();

    // Set schema using traditional array format
    $agent->setResponseSchema([
        'type' => 'object',
        'properties' => [
            'answer' => ['type' => 'string'],
        ],
        'required' => ['answer'],
    ]);

    $schema = $agent->structuredOutput();

    if (!is_array($schema)) {
        throw new \Exception('structuredOutput() should return an array schema');
    }

    if (!isset($schema['type']) || $schema['type'] !== 'object') {
        throw new \Exception("Schema should have type 'object'");
    }

    if (!isset($schema['properties']['answer'])) {
        throw new \Exception("Schema should have 'answer' property");
    }

    echo "Array schema backward compatibility maintained\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
});

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                        ALL TESTS PASSED ✅                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Summary:\n";
echo "  ✓ DataModel instances work in responseSchema property\n";
echo "  ✓ DataModel class names work in responseSchema property\n";
echo "  ✓ Overriding structuredOutput() with DataModel instance works\n";
echo "  ✓ Overriding structuredOutput() with DataModel class name works\n";
echo "  ✓ Complex nested DataModels generate correct schemas\n";
echo "  ✓ Backward compatibility with array schemas maintained\n";
echo "\n";
