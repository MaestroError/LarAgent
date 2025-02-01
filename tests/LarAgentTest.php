<?php

use LarAgent\LarAgent;
use LarAgent\History\InMemoryChatHistory;
use LarAgent\Message;
use LarAgent\Core\Enums\Role;
use LarAgent\Tests\Fakes\FakeLlmDriver;
use LarAgent\Tool;

it('can setup LarAgent', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory, [
        'model' => 'gpt-4o-mini',
    ]);

    expect($agent)->toBeInstanceOf(LarAgent::class);
    expect($agent->getModel())->toBe('gpt-4o-mini');
});

it('can set and get instructions', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory);

    $instructions = 'You are a helpful assistant.';
    $agent->withInstructions($instructions);

    expect($agent->getInstructions())->toBe($instructions);
});

it('can set and get message', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory);

    $message = Message::user('Hello');
    $agent->withMessage($message);

    expect($agent->getCurrentMessage())->toBe($message);
});

it('can set and get response schema', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory);

    $schema = [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string'],
        ],
        'required' => ['message'],
    ];
    $agent->structured($schema);

    expect($agent->getResponseSchema())->toBe($schema);
});

it('can run and get response', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory);

    $message = Message::user('Hello');
    $agent->withInstructions('You are a helpful assistant.')
          ->withMessage($message);

    $driver->addMockResponse('stop', [
        'content' => 'Hi there!',
    ]);

    $response = $agent->run();

    expect($response)->toBeInstanceOf(\LarAgent\Messages\AssistantMessage::class);
    expect((string) $response)->toBe('Hi there!');
    expect($response['content'])->toBe('Hi there!');
});

it('can run with tools', function () {
    $driver = new FakeLlmDriver();
    $chatHistory = new InMemoryChatHistory('test-chat-history');
    $agent = LarAgent::setup($driver, $chatHistory);

    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
        ->setRequired('location')
        ->setMetaData(['sent_at' => '2024-01-01'])
        ->setCallback(function ($location, $unit = 'fahrenheit') {
            return 'The weather in '.$location.' is 72 degrees '.$unit;
        });

    $userMessage = Message::user('What\'s the weather like in Boston and Los Angeles? I prefer celsius');
    $instructions = 'You are weather assistant and always respond using celsius. If it provided as fahrenheit, convert it to celsius.';

    $agent->setTools([$tool])
        ->withInstructions($instructions)
        ->withMessage($userMessage);
        
        
    $agent->afterResponse(function ($agent, $message) {
        $message->setContent($message . '. Checked at 2024-01-01');
    });

    $driver->addMockResponse('tool_calls', [
        'toolName' => 'get_current_weather',
        'arguments' => json_encode(['location' => 'Boston', 'unit' => 'celsius']),
    ]);

    $driver->addMockResponse('stop', [
        'content' => 'The weather in Boston is 22 degrees celsius',
    ]);

    $response = $agent->run();

    // Ensure tools are set
    expect($agent->getTools())->toContain($tool);
    expect((string) $response)->toBe('The weather in Boston is 22 degrees celsius. Checked at 2024-01-01');

    // Ensure LarAgent mutates history correctly
    $history = $chatHistory->toArray();
    expect($history)->toContain($userMessage->toArray());
});
