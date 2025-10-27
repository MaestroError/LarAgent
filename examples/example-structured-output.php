<?php

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\History\InMemoryChatHistory;
use LarAgent\LarAgent;
use LarAgent\Message;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tool;

// Configuration options
$config = [
    'model' => 'gpt-4o-mini',
];

// Setup
$yourApiKey = include __DIR__.'/../openai-api-key.php';
$driver = new OpenAiDriver(['api_key' => $yourApiKey]);
$chatKey = 'structured-output-example';
$chatHistory = new InMemoryChatHistory($chatKey);

$agent = LarAgent::setup($driver, $chatHistory, [
    'model' => $config['model'],
]);

// Define a structured output schema for weather information
$weatherInfoSchema = [
    'name' => 'weather_info',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'locations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                        'weather' => ['type' => 'string'],
                    ],
                    'required' => ['city', 'weather'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['locations'],
        'additionalProperties' => false,
    ],
    'strict' => true,
];

// Create a weather tool
function get_current_weather($location, $unit = 'celsius')
{
    // Simulate weather API call
    return 'The weather in '.$location.' is 22 degrees '.$unit;
}

$toolName = 'get_current_weather';
$tool = Tool::create($toolName, 'Get the current weather in a given location');
$tool->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
    ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
    ->setRequired('location')
    ->setMetaData(['checked_at' => date('Y-m-d')])
    ->setCallback('get_current_weather');

// Set up the user message and instructions
$userMessage = Message::user('What\'s the weather like in Boston and Los Angeles? I prefer celsius');
$instructions = 'You are a weather assistant and always respond using celsius. If temperature is provided in fahrenheit, convert it to celsius.';

// Configure the agent with tools, structured output schema, instructions, and the user message
$agent->setTools([$tool])
    ->structured($weatherInfoSchema)
    ->withInstructions($instructions)
    ->withMessage($userMessage);

// Add a hook to modify the tool result before it's sent back to the LLM
$agent->afterToolExecution(function ($agent, $tool, $toolCall, &$result) {
    $checkedAt = $tool->getMetaData()['checked_at'];
    if ($checkedAt) {
        $result = $result.'. Data checked on '.$checkedAt;
    }
});

// Add a hook to log token usage after each response
$agent->afterSend(function ($agent, $history, $message) use ($chatKey) {
    if (! ($message instanceof ToolCallMessage)) {
        $usage = $message->getMetadata()['usage'];
        echo "📊 {$usage->totalTokens} tokens used in chat: {$chatKey}\n";
    }
});

// Run the agent and get the structured response
echo "🚀 Running LarAgent with structured output...\n\n";
echo "💬 User Message: {$userMessage->getContent()}\n\n";
echo "🤖 Response (structured format):\n";

$response = $agent->run();

// Output the structured response
echo json_encode($response, JSON_PRETTY_PRINT);
echo "\n\n✅ Example completed\n";
