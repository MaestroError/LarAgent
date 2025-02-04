<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\History\JsonChatHistory;
use LarAgent\LarAgent;
use LarAgent\Message;

// Setup
$yourApiKey = include 'openai-api-key.php';
$driver = new OpenAiDriver(['api_key' => $yourApiKey]);
$chatKey = 'test-json-history';
$chatHistory = new JsonChatHistory($chatKey, [
    'folder' => __DIR__.'/chat-history',
]);

$agent = LarAgent::setup($driver, $chatHistory, [
    'model' => 'gpt-4o-mini',
]);

$userMessage = Message::user('Please remind me my name');
$instuctions = 'You are handfull assistant and you always use my name in the conversation';

$agent->withInstructions($instuctions)
    ->withMessage($userMessage);

$response = $agent->run();

echo $response;
