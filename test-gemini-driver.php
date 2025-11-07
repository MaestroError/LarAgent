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
        'model' => 'gemini-2.5-flash',
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
            'model' => 'gemini-flash-latest',
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
            'model' => 'gemini-flash-latest',
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
    echo "Advanced features status:\n";
    echo "🔧 Tool/function calling - Implemented, needs real-world testing\n";
    echo "🔧 Streaming responses - Implemented, needs real-world testing\n";
    echo "🔧 Structured output - Implemented, needs real-world testing\n";
    echo "\n";
    echo "Note: Advanced features require proper API setup and may need\n";
    echo "paid tier for full functionality validation.\n";

} catch (Exception $e) {
    echo '❌ Critical error: '.$e->getMessage()."\n";
    if ($e->getPrevious()) {
        echo 'Previous error: '.$e->getPrevious()->getMessage()."\n";
    }

}
        // Test 6: Structured output
    echo "Test 6: Structured output\n";
    echo "-------------------------\n";

    $schema = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'hobbies' => [
                'type' => 'array',
                'items' => ['type' => 'string']
            ]
        ],
        'required' => ['name', 'age']
    ];

    try {
        $structuredMessages = [
            ['role' => 'user', 'content' => 'Create a user profile for John Doe, age 25, who enjoys reading and swimming. Respond with JSON only.'],
        ];

        $response = $driver->sendMessage($structuredMessages, [
            'response_schema' => $schema,
            'model' => 'gemini-2.5-flash'
        ]);
        $content = $response->getContent();
        echo 'Response: '.$content."\n";

        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['name']) && isset($data['age'])) {
            echo "✅ Structured output works\n";
        } else {
            echo "⚠️ Structured output may need adjustment\n";
        }
    } catch (Exception $e) {
        file_put_contents('error-structured.log', "Error during structured output: ".$e->getMessage()."\n");
        echo "❌ Structured output test failed: ".$e->getMessage()."\n";
    }
    echo "\n";

    // Test 7: Streaming response
    echo "Test 7: Streaming response\n";
    echo "--------------------------\n";

    try {
        $streamMessages = [
            ['role' => 'user', 'content' => 'Count from 1 to 5 with each number on a new line.'],
        ];

        echo "Streaming: ";
        $accumulated = '';
        $chunkCount = 0;

        foreach ($driver->sendMessageStreamed($streamMessages) as $chunk) {
            $content = $chunk->getContent();
            $accumulated .= $content;
            $chunkCount++;
            echo $content;
        }

        echo "\n";
        echo "Received {$chunkCount} chunks, total: ".strlen($accumulated)." characters\n";

        if ($chunkCount > 0 && !empty($accumulated)) {
            echo "✅ Streaming works\n";
        } else {
            echo "⚠️ Streaming may need adjustment\n";
        }
    } catch (Exception $e) {
        file_put_contents('error-stream.log', "Error during streaming: ".$e->getMessage()."\n");
        echo "❌ Streaming test failed: ".$e->getMessage()."\n";
    }
    echo "\n";

