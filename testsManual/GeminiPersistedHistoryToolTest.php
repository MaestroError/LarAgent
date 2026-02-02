<?php

/**
 * Gemini Persisted History Tool Test
 *
 * This test reproduces Issue #131: Gemini Driver fails with "function_response.name"
 * error when tool responses are in persisted chat history.
 *
 * The bug occurs because:
 * 1. ToolResultMessage stores tool_name inside content array
 * 2. ToolResultMessage::fromArray() expects tool_name at top level
 * 3. When history is loaded from cache/file/database, tool_name is lost
 * 4. Gemini API requires function_response.name but it's empty
 */

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Facades\Cache;
use LarAgent\Agent;
use LarAgent\History\CacheChatHistory;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Tool;
use LarAgent\ToolCall;

// Create minimal Laravel app for testing
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton('events', function () {
    return new Illuminate\Events\Dispatcher;
});
$app->singleton('cache', function () {
    return new Illuminate\Cache\CacheManager($GLOBALS['app']);
});
$app['config'] = new Illuminate\Config\Repository([
    'cache' => [
        'default' => 'array',
        'stores' => [
            'array' => [
                'driver' => 'array',
            ],
        ],
    ],
]);
$GLOBALS['app'] = $app;
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

// Configuration function
function config(string $key): mixed
{
    $yourApiKey = include __DIR__.'/gemini-api-key.php';

    $config = [
        'laragent.default_driver' => LarAgent\Drivers\Gemini\GeminiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.fallback_provider' => null,
        'laragent.providers.gemini' => [
            'label' => 'gemini',
            'api_key' => $yourApiKey,
            'driver' => LarAgent\Drivers\Gemini\GeminiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-2.5-flash',
        ],
    ];

    return $config[$key] ?? null;
}

// ============================================================================
// Test Functions
// ============================================================================

function printHeader(string $title): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 70) . "\n\n";
}

function printSuccess(string $message): void
{
    echo "✅ $message\n";
}

function printError(string $message): void
{
    echo "❌ $message\n";
}

function printInfo(string $message): void
{
    echo "ℹ️  $message\n";
}

// ============================================================================
// Test 1: Verify the serialization/deserialization bug exists
// ============================================================================

function testToolResultMessageSerialization(): bool
{
    printHeader('Test 1: ToolResultMessage Serialization/Deserialization');

    // Create a ToolResultMessage
    $originalMessage = new ToolResultMessage(
        'The weather in Boston is 22°C and sunny.',
        'tool_call_123',
        'get_weather'
    );

    printInfo("Original message tool_name: " . $originalMessage->getToolName());

    // Serialize to array (as storage would do)
    $serialized = $originalMessage->toArray();

    printInfo("Serialized data:");
    print_r($serialized);

    // Check if tool_name is at top level
    if (isset($serialized['tool_name'])) {
        printInfo("tool_name IS at top level: " . $serialized['tool_name']);
    } else {
        printInfo("tool_name is NOT at top level");
        if (is_array($serialized['content']) && isset($serialized['content']['tool_name'])) {
            printInfo("tool_name is inside content: " . $serialized['content']['tool_name']);
        }
    }

    // Deserialize (as loading from storage would do)
    $restored = ToolResultMessage::fromArray($serialized);

    printInfo("Restored message tool_name: '" . $restored->getToolName() . "'");

    // Verify the tool_name is preserved
    if ($restored->getToolName() === 'get_weather') {
        printSuccess("Tool name preserved after serialization round-trip");
        return true;
    } else {
        printError("Tool name LOST after serialization round-trip!");
        printError("Expected: 'get_weather', Got: '" . $restored->getToolName() . "'");
        return false;
    }
}

// ============================================================================
// Test 2: Verify MessageArray deserialization preserves tool_name
// ============================================================================

function testMessageArrayDeserialization(): bool
{
    printHeader('Test 2: MessageArray Deserialization');

    // Create a ToolResultMessage
    $originalMessage = new ToolResultMessage(
        'The weather in Boston is 22°C and sunny.',
        'tool_call_123',
        'get_weather'
    );

    // Create a MessageArray with the message
    $originalArray = new MessageArray($originalMessage);

    // Serialize to array
    $serialized = $originalArray->toArray();

    printInfo("Serialized MessageArray:");
    print_r($serialized);

    // Deserialize back
    $restoredArray = MessageArray::fromArray($serialized);

    // Get the first message
    $restoredMessage = $restoredArray->first();

    printInfo("Restored message type: " . get_class($restoredMessage));
    printInfo("Restored message tool_name: '" . $restoredMessage->getToolName() . "'");

    if ($restoredMessage->getToolName() === 'get_weather') {
        printSuccess("Tool name preserved through MessageArray round-trip");
        return true;
    } else {
        printError("Tool name LOST through MessageArray round-trip!");
        return false;
    }
}

// ============================================================================
// Test 3: Verify backward compatibility with old stored data (no top-level tool_name)
// ============================================================================

function testBackwardCompatibilityWithOldStoredData(): bool
{
    printHeader('Test 3: Backward Compatibility with Old Stored Data');

    // Simulate OLD stored data format (before the fix) - no tool_name at top level
    $oldFormatData = [
        'role' => 'tool',
        'content' => [
            'content' => 'The weather in Boston is 22°C and sunny.',
            'tool_call_id' => 'tool_call_123',
            'tool_name' => 'get_weather',  // Only exists inside content
        ],
        'tool_call_id' => 'tool_call_123',
        // Note: NO tool_name at top level - this is the old format
        'message_uuid' => 'msg_old_format',
        'message_created' => '2026-01-01T00:00:00+00:00',
    ];

    printInfo("Old format data (no top-level tool_name):");
    print_r($oldFormatData);

    // Deserialize using fromArray
    $restored = ToolResultMessage::fromArray($oldFormatData);

    printInfo("Restored message tool_name: '" . $restored->getToolName() . "'");

    if ($restored->getToolName() === 'get_weather') {
        printSuccess("Backward compatibility: tool_name extracted from nested content");
        return true;
    } else {
        printError("Backward compatibility FAILED: tool_name not extracted");
        return false;
    }
}

// ============================================================================
// Test 4: Full integration test with Gemini (requires API key)
// ============================================================================

function testGeminiWithPersistedHistory(): bool
{
    printHeader('Test 4: Full Gemini Integration Test (Simulated Persistence)');

    // Skip if no API key
    $apiKey = @include __DIR__.'/gemini-api-key.php';
    if (empty($apiKey) || $apiKey === 'your-api-key-here') {
        printInfo("Skipping: No Gemini API key configured");
        return true;
    }

    try {
        // Create a simple tool agent
        $weatherTool = Tool::create('get_weather', 'Get the current weather in a given location')
            ->addProperty('location', 'string', 'The city and state')
            ->setRequired('location')
            ->setCallback(function ($location) {
                return "The weather in {$location} is 22°C and sunny.";
            });

        // Build messages that simulate a conversation history with tool call
        // This time asking a follow-up about the SAME topic
        $messages = [
            new LarAgent\Messages\SystemMessage('You are a helpful weather assistant. Be concise.'),
            new LarAgent\Messages\UserMessage('What is the weather in Boston?'),
            new ToolCallMessage([
                new ToolCall('tool_call_001', 'get_weather', '{"location": "Boston"}'),
            ]),
            new ToolResultMessage(
                'The weather in Boston is 22°C and sunny.',
                'tool_call_001',
                'get_weather'
            ),
            new LarAgent\Messages\AssistantMessage('The weather in Boston is currently 22°C and sunny!'),
        ];

        // Simulate serialization/deserialization (like cache would do)
        $messageArray = new MessageArray(...$messages);
        $serialized = $messageArray->toArray();

        printInfo("Serialized conversation history:");
        foreach ($serialized as $i => $msg) {
            printInfo("  [$i] role: {$msg['role']}");
        }

        // Deserialize
        $restored = MessageArray::fromArray($serialized);

        // Check tool result message
        foreach ($restored as $msg) {
            if ($msg instanceof ToolResultMessage) {
                printInfo("Tool result tool_name after restore: '" . $msg->getToolName() . "'");
                if (empty($msg->getToolName())) {
                    printError("Bug confirmed: tool_name is empty after deserialization");
                    return false;
                }
            }
        }

        // Add a follow-up question about the same topic
        $restored->add(new LarAgent\Messages\UserMessage('Is that good weather for a walk?'));

        // Now try to send to Gemini API with the restored history
        $driver = new LarAgent\Drivers\Gemini\GeminiDriver([
            'apiKey' => $apiKey,
            'model' => 'gemini-2.5-flash',
        ]);

        printInfo("Sending restored history + new message to Gemini API...");

        // This is where the bug manifests - the API will reject the request
        // because functionResponse.name is empty
        $response = $driver->sendMessage(
            iterator_to_array($restored),
            []  // No tools needed for follow-up
        );

        printSuccess("API call succeeded!");
        printInfo("Response: " . substr($response->getContentAsString(), 0, 100) . "...");
        return true;

    } catch (Exception $e) {
        // Check if it's just a missing API key error
        if (strpos($e->getMessage(), 'requires an API key') !== false) {
            printInfo("Skipping API call: No Gemini API key configured");
            printSuccess("Data serialization/deserialization verified - API test skipped");
            return true;
        }
        
        printError("API call failed: " . $e->getMessage());
        if (strpos($e->getMessage(), 'function_response.name') !== false) {
            printError("This is the exact bug from Issue #131!");
        }
        
        // Debug: print raw API response if available
        if (strpos($e->getMessage(), 'Unexpected response format') !== false) {
            printInfo("Debug: Checking last response from driver...");
            $lastResponse = $driver->getLastResponse();
            if ($lastResponse) {
                printInfo("Raw API response:");
                print_r($lastResponse);
            }
        }
        
        return false;
    }
}

// ============================================================================
// Run all tests
// ============================================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  Gemini Persisted History Tool Test - Issue #131 Reproduction        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

$results = [
    'serialization' => testToolResultMessageSerialization(),
    'messageArray' => testMessageArrayDeserialization(),
    'backwardCompat' => testBackwardCompatibilityWithOldStoredData(),
    'integration' => testGeminiWithPersistedHistory(),
];

printHeader('Test Results Summary');

$passed = 0;
$failed = 0;

foreach ($results as $name => $result) {
    if ($result) {
        printSuccess("$name: PASSED");
        $passed++;
    } else {
        printError("$name: FAILED");
        $failed++;
    }
}

echo "\n";
echo "Total: " . ($passed + $failed) . " tests, $passed passed, $failed failed\n";
echo "\n";

if ($failed > 0) {
    echo "The bug from Issue #131 has been reproduced!\n";
    echo "The fix should ensure tool_name is preserved during serialization/deserialization.\n";
}

exit($failed > 0 ? 1 : 0);
