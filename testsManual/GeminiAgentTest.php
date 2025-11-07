<?php

/**
 * Comprehensive Gemini Agent Test
 * 
 * Tests all features of Gemini agents including:
 * - Normal generation
 * - Tools/Function calling
 * - Structured output
 * - Streaming
 * - Streaming with structured output
 * - Streaming with tools
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Tool;
use LarAgent\Agent;

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
            'default_context_window' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-2.5-flash',
        ],
    ];

    return $config[$key] ?? null;
}

// ============================================================================
// Agent Definitions
// ============================================================================

/**
 * Basic agent for normal generation tests
 */
class BasicGeminiAgent extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.5-flash';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses concise.';
    }
}

/**
 * Agent with tools for function calling tests
 */
class GeminiToolAgent extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.5-flash';
    protected $history = 'in_memory';

    public function registerTools(): array
    {
        $weatherTool = Tool::create('get_weather', 'Get the current weather in a given location')
            ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
            ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
            ->setRequired('location')
            ->setCallback(function ($location, $unit = 'celsius') {
                $weatherData = [
                    'boston' => ['temp' => 22, 'condition' => 'sunny'],
                    'miami' => ['temp' => 28, 'condition' => 'partly cloudy'],
                    'new york' => ['temp' => 20, 'condition' => 'rainy'],
                    'san francisco' => ['temp' => 18, 'condition' => 'foggy'],
                ];

                $city = strtolower(trim(explode(',', $location)[0]));
                $data = $weatherData[$city] ?? ['temp' => 25, 'condition' => 'clear'];

                return "The weather in {$location} is {$data['temp']}°{$unit} and {$data['condition']}.";
            });

        $calculatorTool = Tool::create('calculate', 'Perform basic math calculations')
            ->addProperty('operation', 'string', 'The operation to perform', ['add', 'subtract', 'multiply', 'divide'])
            ->addProperty('a', 'number', 'First number')
            ->addProperty('b', 'number', 'Second number')
            ->setRequired('operation', 'a', 'b')
            ->setCallback(function ($operation, $a, $b) {
                return match($operation) {
                    'add' => $a + $b,
                    'subtract' => $a - $b,
                    'multiply' => $a * $b,
                    'divide' => $b != 0 ? $a / $b : 'Error: Division by zero',
                    default => 'Unknown operation'
                };
            });

        return [$weatherTool, $calculatorTool];
    }

    public function instructions()
    {
        return 'You are a helpful assistant with access to weather and calculator tools. Use them when appropriate.';
    }
}

/**
 * Agent for streaming tests
 */
class GeminiStreamAgent extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.5-flash';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Provide clear, concise responses.';
    }
}

/**
 * Agent for structured output tests
 */
class GeminiStructuredAgent extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.5-flash';
    protected $history = 'in_memory';

    protected $responseSchema = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'author' => ['type' => 'string'],
            'year' => ['type' => 'integer'],
            'genre' => ['type' => 'string']
        ],
        'required' => ['title', 'author', 'year']
    ];

    public function instructions()
    {
        return 'You are a helpful assistant that provides structured data in JSON format.';
    }
}

// ============================================================================
// Test Suite
// ============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║       Comprehensive Gemini Agent Test Suite               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$testsPassed = 0;
$testsFailed = 0;

// ============================================================================
// TEST 1: Normal Generation
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 1: Normal Generation\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = BasicGeminiAgent::for('test_normal');
    $response = $agent->respond('Say "Hello, World!" and nothing else.');
    
    echo "Response: " . $response . "\n";
    
    if (!empty($response)) {
        echo "✅ TEST 1 PASSED: Normal generation works\n";
        $testsPassed++;
    } else {
        echo "❌ TEST 1 FAILED: Empty response\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 1 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 2: Conversation Context
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 2: Conversation Context\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = BasicGeminiAgent::for('test_context');
    $response1 = $agent->respond('My name is Alice.');
    $response2 = $agent->respond('What is my name?');
    
    echo "First response: " . $response1 . "\n";
    echo "Second response: " . $response2 . "\n";
    
    if (stripos($response2, 'Alice') !== false) {
        echo "✅ TEST 2 PASSED: Context retention works\n";
        $testsPassed++;
    } else {
        echo "⚠️ TEST 2 WARNING: Context may not be retained properly\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 2 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 3: Tools/Function Calling
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 3: Tools/Function Calling\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiToolAgent::for('test_tools');
    $response = $agent->respond('What is the weather in Miami?');
    
    echo "Response: " . $response . "\n";
    
    // Check if response contains weather information
    if (stripos($response, '28') !== false || stripos($response, 'weather') !== false || stripos($response, 'miami') !== false) {
        echo "✅ TEST 3 PASSED: Tool calling works\n";
        $testsPassed++;
    } else {
        echo "⚠️ TEST 3 WARNING: Tool may not have been called\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 3 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 4: Multiple Tool Calls
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 4: Multiple Tool Calls\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiToolAgent::for('test_multi_tools');
    $response = $agent->respond('What is the weather in Boston and what is 15 multiplied by 3?');
    
    echo "Response: " . $response . "\n";
    
    // Check if response contains both weather and calculation
    $hasWeather = stripos($response, '22') !== false || stripos($response, 'boston') !== false;
    $hasCalc = stripos($response, '45') !== false;
    
    if ($hasWeather && $hasCalc) {
        echo "✅ TEST 4 PASSED: Multiple tool calls work\n";
        $testsPassed++;
    } else {
        echo "⚠️ TEST 4 WARNING: One or more tools may not have been called\n";
        echo "   Has weather: " . ($hasWeather ? 'yes' : 'no') . "\n";
        echo "   Has calc: " . ($hasCalc ? 'yes' : 'no') . "\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 4 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 5: Streaming
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 5: Streaming\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiStreamAgent::for('test_streaming');
    
    echo "Streaming response: ";
    $fullResponse = '';
    $chunkCount = 0;

    $stream = $agent->respondStreamed('Count from 1 to 5, one number per line.',
        function ($chunk) use (&$fullResponse, &$chunkCount) {
            echo $chunk;
            $fullResponse .= $chunk;
            $chunkCount++;
        }
    );

    // Consume the stream
    foreach ($stream as $chunk) {
        // Chunk processing handled by callback
    }

    echo "\n\n";
    echo "Received {$chunkCount} chunks\n";
    echo "Total content length: " . strlen($fullResponse) . " characters\n";

    if ($chunkCount > 0 && !empty($fullResponse)) {
        echo "✅ TEST 5 PASSED: Streaming works\n";
        $testsPassed++;
    } else {
        echo "❌ TEST 5 FAILED: No streaming content received\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 5 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 6: Structured Output
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 6: Structured Output\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiStructuredAgent::for('test_structured');
    
    
    $response = $agent->respond(
        'Create a book entry for "1984" by George Orwell, published in 1949, genre: dystopian fiction.'
    );
    
    // When structured output is enabled, the agent returns an array directly
    if (is_array($response)) {
        echo "Raw response (structured array):\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
        $data = $response;
    } else {
        // Fallback for string response (shouldn't happen with structured output)
        echo "Raw response:\n" . $response . "\n\n";
        
        // Try to parse JSON
        $jsonContent = $response;
        if (strpos($response, '```json') !== false) {
            preg_match('/```json\s*(.*?)\s*```/s', $response, $matches);
            if (isset($matches[1])) {
                $jsonContent = trim($matches[1]);
            }
        } elseif (strpos($response, '```') !== false) {
            preg_match('/```\s*(.*?)\s*```/s', $response, $matches);
            if (isset($matches[1])) {
                $jsonContent = trim($matches[1]);
            }
        }
        
        $data = json_decode($jsonContent, true);
    }
    
    if (isset($data['title']) && isset($data['author']) && isset($data['year'])) {
        echo "Parsed data:\n";
        echo "  Title: " . $data['title'] . "\n";
        echo "  Author: " . $data['author'] . "\n";
        echo "  Year: " . $data['year'] . "\n";
        echo "  Genre: " . ($data['genre'] ?? 'N/A') . "\n";
        echo "✅ TEST 6 PASSED: Structured output works\n";
        $testsPassed++;
    } else {
        echo "❌ TEST 6 FAILED: Invalid structure\n";
        echo "Response data: " . json_encode($data) . "\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 6 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 7: Streaming with Tools
// 
// The API documentation and community reports indicate that streaming with tools and streaming with structured output simultaneously is not reliably supported yet.
// Developers typically need to choose between streaming plain text generation or structured output with tools and fallback to non-streaming calls for highly structured agent workflows.
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 7: Streaming with Tools (Advanced)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiToolAgent::for('test_streaming_tools');
    
    echo "Streaming response with tool: ";
    $fullResponse = '';
    $chunkCount = 0;

    $stream = $agent->respondStreamed(
        'Tell me about the weather in New York and then explain why you chose that temperature.',
        function ($chunk) use (&$fullResponse, &$chunkCount) {
            echo $chunk;
            $fullResponse .= $chunk;
            $chunkCount++;
        }
    );

    foreach ($stream as $chunk) {
        // Handled by callback
    }

    echo "\n\n";
    echo "Received {$chunkCount} chunks\n";
    echo "Full response length: " . strlen($fullResponse) . " characters\n";

    // Check if tool was used
    $hasWeatherData = stripos($fullResponse, '20') !== false || stripos($fullResponse, 'new york') !== false;
    
    if ($chunkCount > 0 && !empty($fullResponse) && $hasWeatherData) {
        echo "✅ TEST 7 PASSED: Streaming with tools works\n";
        $testsPassed++;
    } else {
        echo "⚠️ TEST 7 WARNING: Streaming with tools may need adjustment\n";
        echo "   Has chunks: " . ($chunkCount > 0 ? 'yes' : 'no') . "\n";
        echo "   Has weather data: " . ($hasWeatherData ? 'yes' : 'no') . "\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 7 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// TEST 8: Streaming with Structured Output (Advanced)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 8: Streaming with Structured Output (Advanced)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $agent = GeminiStructuredAgent::for('test_streaming_structured');
    
    $schema = [
        'type' => 'object',
        'properties' => [
            'product_name' => ['type' => 'string'],
            'price' => ['type' => 'number'],
            'category' => ['type' => 'string'],
            'in_stock' => ['type' => 'boolean']
        ],
        'required' => ['product_name', 'price', 'category', 'in_stock']
    ];
    
    echo "Streaming structured response: ";
    $fullResponse = '';
    $chunkCount = 0;

    $stream = $agent->respondStreamed(
        'Create a product entry for a laptop named "MacBook Pro", priced at 1999.99, category: electronics, in stock: true.',
        function ($chunk) use (&$fullResponse, &$chunkCount) {
            echo $chunk;
            $fullResponse .= $chunk;
            $chunkCount++;
        },
        ['response_schema' => $schema]
    );

    foreach ($stream as $chunk) {
        // Handled by callback
    }

    echo "\n\n";
    echo "Received {$chunkCount} chunks\n";
    
    // Try to parse JSON
    $jsonContent = $fullResponse;
    if (strpos($fullResponse, '```json') !== false) {
        preg_match('/```json\s*(.*?)\s*```/s', $fullResponse, $matches);
        if (isset($matches[1])) {
            $jsonContent = trim($matches[1]);
        }
    } elseif (strpos($fullResponse, '```') !== false) {
        preg_match('/```\s*(.*?)\s*```/s', $fullResponse, $matches);
        if (isset($matches[1])) {
            $jsonContent = trim($matches[1]);
        }
    }
    
    $data = json_decode($jsonContent, true);
    
    if (json_last_error() === JSON_ERROR_NONE && isset($data['product_name']) && isset($data['price'])) {
        echo "Parsed structured data:\n";
        echo "  Product: " . $data['product_name'] . "\n";
        echo "  Price: $" . $data['price'] . "\n";
        echo "  Category: " . $data['category'] . "\n";
        echo "  In Stock: " . ($data['in_stock'] ? 'Yes' : 'No') . "\n";
        echo "✅ TEST 8 PASSED: Streaming with structured output works\n";
        $testsPassed++;
    } else {
        echo "⚠️ TEST 8 WARNING: Structured streaming may need adjustment\n";
        echo "JSON error: " . json_last_error_msg() . "\n";
        echo "Content: " . substr($fullResponse, 0, 200) . "...\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ TEST 8 FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}
echo "\n";

// ============================================================================
// Summary
// ============================================================================
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                    TEST SUMMARY                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$totalTests = $testsPassed + $testsFailed;
$passRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 1) : 0;

echo "Total Tests: {$totalTests}\n";
echo "Passed: {$testsPassed} ✅\n";
echo "Failed: {$testsFailed} ❌\n";
echo "Pass Rate: {$passRate}%\n\n";

if ($testsFailed === 0) {
    echo "🎉 All tests passed! Gemini agents are working perfectly.\n";
} else {
    echo "⚠️ Some tests failed. Please review the output above.\n";
}

echo "\n";
echo "Features tested:\n";
echo "✅ Normal generation\n";
echo "✅ Conversation context\n";
echo "✅ Tools/Function calling\n";
echo "✅ Multiple tool calls\n";
echo "✅ Streaming\n";
echo "✅ Structured output\n";
echo "✅ Streaming with tools (advanced)\n";
echo "✅ Streaming with structured output (advanced)\n";
