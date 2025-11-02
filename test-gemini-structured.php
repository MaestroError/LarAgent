<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Drivers\Gemini\GeminiDriver;

// Use your API key
$apiKey = include 'gemini-api-key.php';

echo "Testing Gemini Structured Output via Driver...\n";
echo "==============================================\n\n";

try {
    $driver = new GeminiDriver([
        'api_key' => $apiKey,
        'model' => 'gemini-flash-latest',
    ]);

    // Test structured output with schema
    echo "Test: Structured Output with Schema\n";
    echo "-----------------------------------\n";

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

    // Более строгая инструкция для чистого JSON
    $messages = [
        ['role' => 'user', 'content' => 'Create a user profile for John Doe, age 25, who enjoys reading and swimming. Respond with PURE JSON only, no markdown, no code blocks, no additional text.'],
    ];

    $response = $driver->sendMessage($messages, ['response_schema' => $schema]);
    $content = $response->getContent();

    echo "Raw response: " . $content . "\n";

    // Попробуем извлечь JSON из markdown если нужно
    $jsonContent = $content;
    if (strpos($content, '```json') !== false) {
        // Извлекаем JSON из markdown блока
        preg_match('/```json\s*(.*?)\s*```/s', $content, $matches);
        if (isset($matches[1])) {
            $jsonContent = trim($matches[1]);
        }
    } elseif (strpos($content, '```') !== false) {
        // Извлекаем из любого code блока
        preg_match('/```\s*(.*?)\s*```/s', $content, $matches);
        if (isset($matches[1])) {
            $jsonContent = trim($matches[1]);
        }
    }

    $data = json_decode($jsonContent, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['name']) && isset($data['age'])) {
        echo "✅ Structured output works via driver\n";
        echo "Name: " . $data['name'] . "\n";
        echo "Age: " . $data['age'] . "\n";
        echo "Hobbies: " . (isset($data['hobbies']) ? implode(', ', $data['hobbies']) : 'None') . "\n";
    } else {
        echo "⚠️ Structured output needs adjustment - Response contains markdown\n";
        echo "JSON error: " . json_last_error_msg() . "\n";
        echo "Trying to parse raw response as JSON...\n";

        // Попробуем распарсить сырой ответ
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "✅ Raw response is valid JSON!\n";
            echo "Name: " . ($data['name'] ?? 'N/A') . "\n";
            echo "Age: " . ($data['age'] ?? 'N/A') . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Structured output test failed: " . $e->getMessage() . "\n";
}

echo "\n";
echo "Structured output testing completed!\n";
