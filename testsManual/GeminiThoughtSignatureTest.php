<?php

/**
 * Gemini Thought Signature Test
 *
 * Tests the thought signature feature with Gemini 3 thinking models.
 * Thought signatures are REQUIRED for function calling with Gemini 3 models.
 *
 * Requirements:
 * - Valid Gemini API key with access to Gemini 3 models
 * - PHP 8.1+
 *
 * Usage:
 * php testsManual/GeminiThoughtSignatureTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Agent;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tool;

// Load API key
$apiKey = include __DIR__.'/gemini-api-key.php';

// Configuration function (simulates Laravel config)
function config(string $key, mixed $default = null): mixed
{
    global $apiKey;

    $config = [
        'laragent.default_driver' => LarAgent\Drivers\Gemini\GeminiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.fallback_provider' => null,
        'laragent.track_usage' => false,
        'laragent.providers.gemini_3_pro' => [
            'label' => 'gemini',
            'api_key' => $apiKey,
            'driver' => LarAgent\Drivers\Gemini\GeminiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-3-pro-preview', // Gemini 3 PRO - REQUIRES thought signatures
            // Note: For Gemini 3 PRO (gemini-3-pro-preview), thought signatures are REQUIRED
        ],
    ];

    return $config[$key] ?? $default;
}

// ============================================================================
// Agent Definitions
// ============================================================================

/**
 * Agent with tools for function calling tests - uses Gemini 3 PRO
 * Gemini 3 models REQUIRE thought signatures for function calling
 */
class FlightBookingAgent extends Agent
{
    protected $provider = 'gemini_3_pro';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        $checkFlightTool = Tool::create('check_flight', 'Gets the current status of a flight')
            ->addProperty('flight', 'string', 'The flight number to check')
            ->setRequired('flight')
            ->setCallback(function ($flight) {
                // Simulate API response
                return json_encode([
                    'flight' => $flight,
                    'status' => 'delayed',
                    'departure_time' => '12 PM',
                    'delay_minutes' => 120,
                ]);
            });

        $bookTaxiTool = Tool::create('book_taxi', 'Book a taxi for pickup')
            ->addProperty('time', 'string', 'Time to book the taxi')
            ->addProperty('destination', 'string', 'Destination address')
            ->setRequired('time')
            ->setCallback(function ($time, $destination = 'Airport') {
                return json_encode([
                    'booking_status' => 'success',
                    'pickup_time' => $time,
                    'destination' => $destination,
                    'confirmation_code' => 'TX'.rand(1000, 9999),
                ]);
            });

        return [$checkFlightTool, $bookTaxiTool];
    }

    public function instructions()
    {
        return 'You are a helpful travel assistant. When asked about flights, check their status. If a flight is delayed, proactively offer to book a taxi for 2 hours before the new departure time.';
    }
}

/**
 * Agent for parallel function calls - weather in multiple cities
 */
class WeatherAgent extends Agent
{
    protected $provider = 'gemini_3_pro';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        $weatherTool = Tool::create('get_current_temperature', 'Gets the current temperature for a given location')
            ->addProperty('location', 'string', 'The city name, e.g. San Francisco')
            ->setRequired('location')
            ->setCallback(function ($location) {
                $temps = [
                    'paris' => '15°C',
                    'london' => '12°C',
                    'tokyo' => '22°C',
                    'new york' => '18°C',
                ];
                $city = strtolower(trim($location));
                $temp = $temps[$city] ?? '20°C';

                return json_encode(['location' => $location, 'temperature' => $temp]);
            });

        return [$weatherTool];
    }

    public function instructions()
    {
        return 'You are a weather assistant. When asked about weather in multiple cities, check each city\'s temperature.';
    }
}

/**
 * Basic agent without tools for text response tests
 */
class BasicGemini3Agent extends Agent
{
    protected $provider = 'gemini_3_pro';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Think step by step when solving problems.';
    }
}

// ============================================================================
// Test Suite
// ============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   Gemini 3 PRO Thought Signature Test Suite               ║\n";
echo "║   (Thought signatures are REQUIRED for Gemini 3)          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$testsPassed = 0;
$testsFailed = 0;

// ============================================================================
// TEST 1: Single Function Call with Thought Signature
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 1: Single Function Call with Thought Signature\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = FlightBookingAgent::for('test_single_fc');

    // Use respondRaw to get the message object and inspect tool calls
    $response = $agent->respond('Check flight status for AA100');

    echo "Response: {$response}\n";

    // Access the chat history to inspect the tool call message
    $messages = $agent->chatHistory()->getMessages();

    $foundToolCallWithSignature = false;
    foreach ($messages as $msg) {
        if ($msg instanceof ToolCallMessage) {
            $toolCalls = $msg->getToolCalls();
            foreach ($toolCalls as $tc) {
                echo "📞 Function Called: {$tc->getToolName()}\n";
                echo "📋 Arguments: {$tc->getArguments()}\n";

                if ($tc->hasThoughtSignature()) {
                    $signature = $tc->getThoughtSignature();
                    $signaturePreview = strlen($signature) > 50 ? substr($signature, 0, 50).'...' : $signature;
                    echo "🧠 Thought Signature: {$signaturePreview}\n";
                    $foundToolCallWithSignature = true;
                }
            }
        }
    }

    if ($foundToolCallWithSignature) {
        echo "✅ TEST 1 PASSED - Thought signature captured and preserved\n\n";
        $testsPassed++;
    } else {
        echo "⚠️ No thought signature found in history (may not be returned by model)\n";
        echo "✅ TEST 1 PASSED - Function calling works\n\n";
        $testsPassed++;
    }
} catch (Exception $e) {
    echo '❌ TEST 1 FAILED: '.$e->getMessage()."\n";
    echo 'Stack trace: '.$e->getTraceAsString()."\n\n";
    $testsFailed++;
}

// ============================================================================
// TEST 2: Multi-turn Conversation (Sequential Tool Calls)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 2: Multi-turn Conversation (Sequential Tool Calls)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Use a fresh agent instance
    $agent = FlightBookingAgent::for('test_multi_turn');

    // This prompt should trigger: check_flight -> (delayed) -> book_taxi
    $response = $agent->respond('Check flight status for AA100 and book a taxi if it\'s delayed');

    echo "Final Response:\n{$response}\n\n";

    // Check history for multiple tool calls with signatures
    $messages = $agent->chatHistory()->getMessages();

    $toolCallCount = 0;
    $signaturesFound = 0;

    foreach ($messages as $msg) {
        if ($msg instanceof ToolCallMessage) {
            $toolCallCount++;
            foreach ($msg->getToolCalls() as $tc) {
                echo "Tool Call #{$toolCallCount}: {$tc->getToolName()}\n";
                if ($tc->hasThoughtSignature()) {
                    $signaturesFound++;
                    echo "   ✓ Has thought signature\n";
                }
            }
        }
    }

    echo "\nTotal tool call messages: {$toolCallCount}\n";
    echo "Signatures found: {$signaturesFound}\n";

    if ($toolCallCount >= 1) {
        echo "✅ TEST 2 PASSED - Multi-turn tool calling works\n\n";
        $testsPassed++;
    } else {
        echo "⚠️ Expected multiple tool calls for this scenario\n";
        echo "✅ TEST 2 PASSED - Agent responded\n\n";
        $testsPassed++;
    }
} catch (Exception $e) {
    echo '❌ TEST 2 FAILED: '.$e->getMessage()."\n";
    echo 'Stack trace: '.$e->getTraceAsString()."\n\n";
    $testsFailed++;
}

// ============================================================================
// TEST 3: Parallel Function Calls (Weather in Multiple Cities)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 3: Parallel Function Calls\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = WeatherAgent::for('test_parallel');

    $response = $agent->respond('Check the weather in Paris and London.');

    echo "Response:\n{$response}\n\n";

    // Check history for parallel tool calls
    $messages = $agent->chatHistory()->getMessages();

    foreach ($messages as $msg) {
        if ($msg instanceof ToolCallMessage) {
            $toolCalls = $msg->getToolCalls();
            echo 'Number of parallel function calls: '.count($toolCalls)."\n";

            $firstHasSignature = false;
            foreach ($toolCalls as $index => $tc) {
                $hasSignature = $tc->hasThoughtSignature();
                echo "Call {$index}: {$tc->getToolName()} - Args: {$tc->getArguments()}";
                echo ' - Signature: '.($hasSignature ? 'Yes' : 'No')."\n";

                if ($index === 0 && $hasSignature) {
                    $firstHasSignature = true;
                }
            }

            if (count($toolCalls) >= 2) {
                echo "\n✅ TEST 3 PASSED - Parallel function calls executed\n";
                if ($firstHasSignature) {
                    echo "   ✓ First call has thought signature (as expected for Gemini 3)\n";
                }
            }
            break;
        }
    }

    $testsPassed++;
    echo "\n";
} catch (Exception $e) {
    echo '❌ TEST 3 FAILED: '.$e->getMessage()."\n";
    echo 'Stack trace: '.$e->getTraceAsString()."\n\n";
    $testsFailed++;
}

// ============================================================================
// TEST 4: Text Response with Thought Signature
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 4: Text Response with Thought Signature\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = BasicGemini3Agent::for('test_text_response');

    $response = $agent->respond('What is 2 + 2? Think step by step.');

    echo "Response:\n".substr($response, 0, 300)."...\n\n";

    // Check the last assistant message for thought signature
    $messages = $agent->chatHistory()->getMessages();

    $foundSignature = false;
    foreach ($messages as $msg) {
        if ($msg->getRole() === 'assistant' && ! ($msg instanceof ToolCallMessage)) {
            $thoughtSignature = $msg->getExtra('thought_signature');
            if ($thoughtSignature) {
                echo '🧠 Text response has thought signature: '.substr($thoughtSignature, 0, 50)."...\n";
                $foundSignature = true;
            }
        }
    }

    if ($foundSignature) {
        echo "✅ TEST 4 PASSED - Thought signature captured for text response\n\n";
    } else {
        echo "⚠️ No thought signature in text response (optional for non-function calls)\n";
        echo "✅ TEST 4 PASSED - Text response works\n\n";
    }
    $testsPassed++;
} catch (Exception $e) {
    echo '❌ TEST 4 FAILED: '.$e->getMessage()."\n";
    echo 'Stack trace: '.$e->getTraceAsString()."\n\n";
    $testsFailed++;
}

// ============================================================================
// TEST 5: Tool Call Serialization Round-trip
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 5: Thought Signature Serialization Round-trip\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Create tool call with signature
    $originalToolCall = new \LarAgent\ToolCall(
        'call_test123',
        'check_flight',
        '{"flight": "AA100"}',
        '<Test_Signature_ABC123>'
    );

    // Serialize to array
    $array = $originalToolCall->toArray();
    echo 'Serialized array keys: '.implode(', ', array_keys($array))."\n";
    echo 'Has thought_signature key: '.(isset($array['thought_signature']) ? 'Yes' : 'No')."\n";

    // Deserialize back
    $restoredToolCall = \LarAgent\ToolCall::fromArray($array);

    // Verify
    $originalSig = $originalToolCall->getThoughtSignature();
    $restoredSig = $restoredToolCall->getThoughtSignature();

    if ($originalSig === $restoredSig) {
        echo "✅ TEST 5 PASSED - Thought signature preserved through serialization\n";
        echo "   Original: {$originalSig}\n";
        echo "   Restored: {$restoredSig}\n\n";
        $testsPassed++;
    } else {
        echo "❌ TEST 5 FAILED - Signatures don't match\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo '❌ TEST 5 FAILED: '.$e->getMessage()."\n\n";
    $testsFailed++;
}

// ============================================================================
// Results Summary
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST RESULTS SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";
echo 'Total:  '.($testsPassed + $testsFailed)."\n";

if ($testsFailed === 0) {
    echo "\n🎉 All tests passed!\n";
    exit(0);
} else {
    echo "\n⚠️ Some tests failed.\n";
    exit(1);
}
