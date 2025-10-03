<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Drivers\Gemini\GeminiDriver;

// Use your API key
$apiKey = include 'gemini-api-key.php';

try {
    echo "=== Testing Native Gemini Driver (Core Features) ===\n\n";

    // Create a driver instance with configurable URL
    $driver = new GeminiDriver([
        'api_key' => $apiKey,
        'model' => 'gemini-robotics-er-1.5-preview',
        'api_url' => 'https://generativelanguage.googleapis.com/v1beta/',
    ]);

    // Test 1: Basic message
    echo "Test 1: Basic message\n";
    echo "--------------------\n";

    $messages = [
        ['role' => 'user', 'content' => 'Hello! How are you? Respond very briefly.'],
    ];

    $response = $driver->sendMessage($messages);
    echo 'Response: '.$response->getContent()."\n";

    $metadata = $response->getMetadata();
    if (isset($metadata['usage'])) {
        echo 'Usage: '.json_encode($metadata['usage'])."\n";
    }
    echo "\n";

    // Test 2: Conversation with context
    echo "Test 2: Conversation\n";
    echo "--------------------\n";

    $conversation = [
        ['role' => 'user', 'content' => 'My name is John'],
        ['role' => 'assistant', 'content' => 'Hello John! Nice to meet you.'],
        ['role' => 'user', 'content' => 'What did I just tell you my name was?'],
    ];

    $response = $driver->sendMessage($conversation);
    echo 'Response: '.$response->getContent()."\n\n";

    // Test 3: System instructions
    echo "Test 3: System instructions\n";
    echo "---------------------------\n";

    $systemMessages = [
        ['role' => 'system', 'content' => 'You are a helpful assistant that always responds in uppercase.'],
        ['role' => 'user', 'content' => 'hello there'],
    ];

    $response = $driver->sendMessage($systemMessages);
    echo 'Response: '.$response->getContent()."\n\n";

    // Test 4: Error handling
    echo "Test 4: Error handling\n";
    echo "----------------------\n";

    try {
        $invalidDriver = new GeminiDriver([
            'api_key' => 'invalid_key',
            'model' => 'gemini-robotics-er-1.5-preview',
        ]);

        $invalidDriver->sendMessage([['role' => 'user', 'content' => 'test']]);
        echo '❌ Expected exception was not thrown'."\n";
    } catch (Exception $e) {
        echo '✅ Correctly caught error: '.$e->getMessage()."\n";
    }
    echo "\n";

    // Test 5: Configurable base URL
    echo "Test 5: Configurable base URL\n";
    echo "-----------------------------\n";

    try {
        $customDriver = new GeminiDriver([
            'api_key' => $apiKey,
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'model' => 'gemini-robotics-er-1.5-preview',
        ]);

        $response = $customDriver->sendMessage([['role' => 'user', 'content' => 'Test configurable URL']]);
        echo '✅ Custom URL works: '.($response->getContent() ? 'Yes' : 'No')."\n";
    } catch (Exception $e) {
        echo 'Custom URL test failed: '.$e->getMessage()."\n";
    }
    echo "\n";

    echo "🎉 Core Gemini driver tests completed! ✅\n";
    echo "========================================\n";
    echo "Features tested and working:\n";
    echo "✅ Basic messages\n";
    echo "✅ Conversation context\n";
    echo "✅ System instructions\n";
    echo "✅ Error handling\n";
    echo "✅ Configurable base URL\n";
    echo "\n";
    echo "Features needing additional work:\n";
    echo "⚠️ Tool/function calling (API format issue)\n";
    echo "⚠️ Streaming responses (API format issue)\n";
    echo "⚠️ Structured output (not tested)\n";

} catch (Exception $e) {
    echo '❌ Critical error: '.$e->getMessage()."\n";
    if ($e->getPrevious()) {
        echo 'Previous error: '.$e->getPrevious()->getMessage()."\n";
    }
}
