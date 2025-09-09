<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Drivers\Gemini\GeminiDriver;
use LarAgent\Messages\AssistantMessage;

// Use your API key
$apiKey = include 'gemini-api-key.php';

try {
    echo "=== Testing Native Gemini Driver ===\n\n";

    // Create a driver instance
    $driver = new GeminiDriver([
        'api_key' => $apiKey,
        'model' => 'gemini-1.5-flash', // or 'gemini-pro'
    ]);

    // Test 1: Basic message
    echo "Test 1: Basic message\n";
    echo "--------------------\n";

    $messages = [
        ['role' => 'user', 'content' => 'Hello! How are you? Respond very briefly.']
    ];

    $response = $driver->sendMessage($messages);

    echo "Response: " . $response->getContent() . "\n";

    // Check metadata
    $metadata = $response->getMetadata();
    if (isset($metadata['usage'])) {
        echo "Usage: " . json_encode($metadata['usage']) . "\n";
    }
    echo "\n";

    // Test 2: Multiple messages conversation
    echo "Test 2: Conversation\n";
    echo "--------------------\n";

    $conversation = [
        ['role' => 'user', 'content' => 'My name is John'],
        ['role' => 'assistant', 'content' => 'Hello John! Nice to meet you.'],
        ['role' => 'user', 'content' => 'What did I just tell you my name was?']
    ];

    $response = $driver->sendMessage($conversation);
    echo "Response: " . $response->getContent() . "\n\n";

    // Test 3: Check last response
    echo "Test 3: Raw response structure\n";
    echo "-----------------------------\n";

    $lastResponse = $driver->getLastResponse();
    if ($lastResponse) {
        echo "Has last response: " . (is_array($lastResponse) ? "yes" : "no") . "\n";
        echo "Response keys: " . implode(', ', array_keys($lastResponse)) . "\n";

        // Show a preview of the response structure
        if (isset($lastResponse['candidates'][0])) {
            $candidate = $lastResponse['candidates'][0];
            echo "Candidate finish reason: " . ($candidate['finishReason'] ?? 'unknown') . "\n";
        }
    }
    echo "\n";

    echo "All tests completed successfully! ✅\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "Previous error: " . $e->getPrevious()->getMessage() . "\n";
    }
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
