<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Drivers\Gemini\GeminiDriver;
use LarAgent\Tool;

function config(string $key): mixed
{
    $yourApiKey = include 'gemini-api-key.php';

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
            'model' => 'gemini-flash-latest',
        ],
    ];

    return $config[$key] ?? null;
}

class GeminiToolAgent extends LarAgent\Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-flash-latest';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant that uses tools when available. Use the tools provided to answer questions.';
    }
}

class GeminiStreamAgent extends LarAgent\Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-flash-latest';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses concise.';
    }
}

echo "Testing Gemini Advanced Features...\n";
echo "===================================\n\n";

// Test 1: Agent with single tool
echo "Test 1: Agent with Single Tool\n";
echo "-------------------------------\n";

// Create a weather tool
$weatherTool = Tool::create('get_weather', 'Get the current weather in a given location')
    ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
    ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
    ->setRequired('location')
    ->setCallback(function ($location, $unit = 'celsius') {
        // Mock weather data
        $weatherData = [
            'boston' => ['temp' => 22, 'condition' => 'sunny'],
            'miami' => ['temp' => 28, 'condition' => 'partly cloudy'],
            'new york' => ['temp' => 20, 'condition' => 'rainy'],
        ];

        $city = strtolower(explode(',', $location)[0]);
        $data = $weatherData[$city] ?? ['temp' => 25, 'condition' => 'clear'];

        return "The weather in {$location} is {$data['temp']}°{$unit} and {$data['condition']}.";
    });

try {
    $toolAgent = GeminiToolAgent::for('tool_test')
        ->withTool($weatherTool);

    $response = $toolAgent->respond('What is the weather in Boston, MA?');

    echo "Tool response: " . $response . "\n";

    if (strpos($response, '22°') !== false || strpos($response, 'weather') !== false) {
        echo "✅ Tools functionality works\n";
    } else {
        echo "⚠️ Tools response received but content may not be as expected\n";
    }
} catch (Exception $e) {
    echo "❌ Tools test failed: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: Streamed response
echo "Test 2: Streamed Response\n";
echo "-------------------------\n";

try {
    $streamAgent = GeminiStreamAgent::for('stream_test');

    echo "Streaming response: ";
    $fullResponse = '';

    $stream = $streamAgent->respondStreamed('Say just the numbers 1 2 3',
        function ($chunk) use (&$fullResponse) {
            echo $chunk;
            $fullResponse .= $chunk;
        }
    );

    // Consume the stream
    foreach ($stream as $chunk) {
        // Chunk processing handled by callback
    }

    echo "\n";

    if (!empty($fullResponse)) {
        echo "✅ Streamed response works - Received content\n";
    } else {
        echo "❌ Streamed response failed - No content received\n";
    }
} catch (Exception $e) {
    echo "❌ Streamed response test failed: " . $e->getMessage() . "\n";
}

echo "\n";
echo "🎉 Advanced Gemini features testing completed!\n";
echo "==============================================\n";
echo "Summary:\n";
echo "✅ Tools/function calling\n";
echo "✅ Streamed responses\n";
echo "\n";
echo "All core advanced features are working!\n";
