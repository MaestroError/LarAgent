<?php

require_once __DIR__.'/vendor/autoload.php';

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
            'model' => 'gemini-1.5-flash-latest',
        ],
    ];

    return $config[$key] ?? null;
}

class GeminiTestAgent extends LarAgent\Agent
{
    protected $provider = 'gemini';

    protected $model = 'gemini-robotics-er-1.5-preview';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant powered by Google Gemini. Keep responses concise.';
    }
}

echo "Testing Gemini Agent...\n";
echo "=======================\n\n";

// Test 1: Basic response
echo "Test 1: Basic response\n";
$response = GeminiTestAgent::for('gemini_test_1')
    ->respond('Hello! What model are you based on?');
echo 'Response: '.$response."\n\n";

// Test 2: Conversation
echo "Test 2: Conversation\n";
$agent = GeminiTestAgent::for('gemini_test_2');
$response1 = $agent->respond('My name is Test User');
$response2 = $agent->respond('What is my name?');

echo 'First response: '.$response1."\n";
echo 'Second response: '.$response2."\n\n";

echo "Gemini agent test completed! ✅\n";
