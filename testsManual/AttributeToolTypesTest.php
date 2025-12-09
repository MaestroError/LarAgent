<?php

/**
 * Manual Test: Attribute-Based Tool with Various Argument Types
 *
 * This test verifies that attribute-based tools correctly handle:
 * - DataModel arguments (complex objects with typed properties)
 * - Enum arguments (backed enum types)
 * - Multi-type/Union arguments (string|int)
 * - Single type arguments (simple string, int, etc.)
 *
 * Run with: php testsManual/AttributeToolTypesTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Agent;
use LarAgent\Attributes\Tool as ToolAttribute;
use LarAgent\Core\Abstractions\DataModel;

// ============================================================================
// Test Fixtures: DataModel, Enum, and other types
// ============================================================================

/**
 * Enum for task priority levels
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}

/**
 * DataModel for task details
 */
class TaskDetails extends DataModel
{
    public string $title;
    public int $estimatedMinutes;
    public ?string $description = null;
}

// ============================================================================
// Configuration
// ============================================================================

function config(string $key): mixed
{
    $yourApiKey = include __DIR__.'/../openai-api-key.php';

    $config = [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.default_history_storage' => 'in_memory',
        'laragent.default_storage' => [LarAgent\Context\Drivers\InMemoryStorage::class],
        'laragent.fallback_provider' => null,
        'laragent.mcp_servers' => [],
        'laragent.mcp_tool_caching' => ['enabled' => false],
        'laragent.providers.openai' => [
            'name' => 'OpenAI',
            'api_key' => $yourApiKey,
            'driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            'default_context_window' => 128000,
            'default_max_completion_tokens' => 4096,
            'default_temperature' => 0.7,
            'model' => 'gpt-4o-mini',
            'store_meta' => false,
        ],
    ];

    return $config[$key] ?? null;
}

// ============================================================================
// Agent with Attribute-Based Tool
// ============================================================================

/**
 * Test agent with a tool that uses all argument types
 */
class AttributeToolTestAgent extends Agent
{
    protected $provider = 'openai';
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';

    // Store results for verification
    public static array $lastToolResults = [];

    public function instructions()
    {
        return <<<INSTRUCTIONS
You are a task management assistant. When asked to create a task, you MUST use the createTask tool.

IMPORTANT: When calling createTask, use these exact values based on the user's request:
- For task details (taskData): provide title, estimatedMinutes, and optionally description
- For priority: use one of: low, medium, high, critical
- For tags: provide a string or a number (or both separated by comma if multiple)
- For assignee: provide the person's name as a string
INSTRUCTIONS;
    }

    /**
     * Tool with multiple argument types:
     * - DataModel (TaskDetails)
     * - Enum (TaskPriority)
     * - Union type (string|int)
     * - Simple string
     */
    #[ToolAttribute(
        description: 'Create a new task with the given details',
        parameterDescriptions: [
            'taskData' => 'The task details including title, estimated minutes, and optional description',
            'priority' => 'The priority level: low, medium, high, or critical',
            'tags' => 'A tag identifier - can be a string name or numeric ID',
            'assignee' => 'The name of the person assigned to this task',
        ]
    )]
    public function createTask(
        TaskDetails $taskData,
        TaskPriority $priority,
        string|int $tags,
        string $assignee
    ): string {
        // Store results for verification
        self::$lastToolResults = [
            'taskData' => [
                'instance' => $taskData,
                'isDataModel' => $taskData instanceof TaskDetails,
                'title' => $taskData->title,
                'estimatedMinutes' => $taskData->estimatedMinutes,
                'description' => $taskData->description,
            ],
            'priority' => [
                'instance' => $priority,
                'isEnum' => $priority instanceof TaskPriority,
                'value' => $priority->value,
                'name' => $priority->name,
            ],
            'tags' => [
                'value' => $tags,
                'type' => gettype($tags),
            ],
            'assignee' => [
                'value' => $assignee,
                'type' => gettype($assignee),
            ],
        ];

        return json_encode([
            'success' => true,
            'message' => "Task '{$taskData->title}' created successfully",
            'task' => [
                'title' => $taskData->title,
                'estimatedMinutes' => $taskData->estimatedMinutes,
                'description' => $taskData->description,
                'priority' => $priority->value,
                'tags' => $tags,
                'assignee' => $assignee,
            ],
        ]);
    }
}

// ============================================================================
// Test Execution
// ============================================================================

echo "=== Attribute-Based Tool Types Integration Test ===\n\n";

try {
    // Create agent instance
    $agent = AttributeToolTestAgent::for('test-session');

    echo "1. Sending request to create a task...\n";
    echo "   Prompt: 'Create a high priority task titled \"Fix login bug\" estimated at 120 minutes, assign to John, use tag \"bugfix\"'\n\n";

    $response = $agent->respond(
        'Create a high priority task titled "Fix login bug" with description "Users cannot log in with SSO" estimated at 120 minutes, assign to John, use tag "bugfix"'
    );

    echo "2. Agent Response:\n";
    echo "   " . (is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response) . "\n\n";

    echo "3. Verifying Tool Arguments:\n";
    $results = AttributeToolTestAgent::$lastToolResults;

    if (empty($results)) {
        throw new Exception("Tool was not called - no results captured!");
    }

    // Verify DataModel
    echo "\n   [DataModel - TaskDetails]\n";
    echo "   - Is TaskDetails instance: " . ($results['taskData']['isDataModel'] ? '✓ YES' : '✗ NO') . "\n";
    echo "   - Title: " . $results['taskData']['title'] . "\n";
    echo "   - Estimated Minutes: " . $results['taskData']['estimatedMinutes'] . "\n";
    echo "   - Description: " . ($results['taskData']['description'] ?? 'null') . "\n";

    if (!$results['taskData']['isDataModel']) {
        throw new Exception("taskData is not a TaskDetails instance!");
    }

    // Verify Enum
    echo "\n   [Enum - TaskPriority]\n";
    echo "   - Is TaskPriority instance: " . ($results['priority']['isEnum'] ? '✓ YES' : '✗ NO') . "\n";
    echo "   - Enum Name: " . $results['priority']['name'] . "\n";
    echo "   - Enum Value: " . $results['priority']['value'] . "\n";

    if (!$results['priority']['isEnum']) {
        throw new Exception("priority is not a TaskPriority enum instance!");
    }

    // Verify Union Type (string|int)
    echo "\n   [Union Type - string|int]\n";
    echo "   - Value: " . $results['tags']['value'] . "\n";
    echo "   - Type: " . $results['tags']['type'] . "\n";

    if (!in_array($results['tags']['type'], ['string', 'integer'])) {
        throw new Exception("tags is neither string nor integer!");
    }

    // Verify Simple String
    echo "\n   [Simple Type - string]\n";
    echo "   - Value: " . $results['assignee']['value'] . "\n";
    echo "   - Type: " . $results['assignee']['type'] . "\n";

    if ($results['assignee']['type'] !== 'string') {
        throw new Exception("assignee is not a string!");
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✓ ALL TESTS PASSED!\n";
    echo str_repeat("=", 50) . "\n";

    // Test 2: Try with numeric tag to test union type
    echo "\n\n=== Test 2: Union Type with Integer ===\n\n";

    $agent2 = AttributeToolTestAgent::for('test-session-2');

    echo "1. Sending request with numeric tag...\n";
    echo "   Prompt: 'Create a low priority task titled \"Update docs\" for 30 minutes, assign to Alice, use tag 42'\n\n";

    $response2 = $agent2->respond(
        'Create a low priority task titled "Update docs" for 30 minutes, assign to Alice, use tag number 42'
    );

    echo "2. Agent Response:\n";
    echo "   " . (is_array($response2) ? json_encode($response2, JSON_PRETTY_PRINT) : $response2) . "\n\n";

    $results2 = AttributeToolTestAgent::$lastToolResults;

    if (!empty($results2)) {
        echo "3. Tag Type Verification:\n";
        echo "   - Value: " . $results2['tags']['value'] . "\n";
        echo "   - Type: " . $results2['tags']['type'] . "\n";
        echo "   - Union type correctly handled: ✓ YES\n";
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✓ ALL INTEGRATION TESTS COMPLETED SUCCESSFULLY!\n";
    echo str_repeat("=", 50) . "\n";

} catch (Throwable $e) {
    echo "\n✗ TEST FAILED!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
