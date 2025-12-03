<?php

use LarAgent\History\InMemoryChatHistory;
use LarAgent\LarAgent;
use LarAgent\Message;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Tool;
use LarAgent\Context\SessionIdentity;

it('can setup LarAgent', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory, [
        'model' => 'gpt-4o-mini',
    ]);

    expect($agent)->toBeInstanceOf(LarAgent::class);
    expect($agent->getModel())->toBe('gpt-4o-mini');
});

it('can set and get instructions', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    $instructions = 'You are a helpful assistant.';
    $agent->withInstructions($instructions);

    expect($agent->getInstructions())->toBe($instructions);
});

it('can set and get message', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    $message = Message::user('Hello');
    $agent->withMessage($message);

    expect($agent->getCurrentMessage())->toBe($message);
});

it('can set and get response schema', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
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
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
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
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
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
        $message->setContent(new TextContent($message->getContentAsString() . '. Checked at 2024-01-01'));
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

    // Ensure the current message is cleared correctly
    expect($agent->getCurrentMessage())->toBeNull();
});

it('excludes parallel_tool_calls from config when set to null', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    $agent->setParallelToolCalls(null);
    $tool = Tool::create('test_tool', 'Test tool')->setCallback(fn () => 'test');
    $agent->setTools([$tool]);

    $reflection = new ReflectionClass($agent);
    $buildConfig = $reflection->getMethod('buildConfig');
    $buildConfig->setAccessible(true);
    $config = $buildConfig->invoke($agent);

    expect($config)->not->toHaveKey('parallel_tool_calls');
});

it('includes optional config values when set', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    $agent->setN(2);
    $agent->setTopP(0.8);
    $agent->setFrequencyPenalty(0.1);
    $agent->setPresencePenalty(0.2);

    $reflection = new ReflectionClass($agent);
    $buildConfig = $reflection->getMethod('buildConfig');
    $buildConfig->setAccessible(true);
    $config = $buildConfig->invoke($agent);

    expect($config->toArray())->toMatchArray([
        'n' => 2,
        'topP' => 0.8,
        'frequencyPenalty' => 0.1,
        'presencePenalty' => 0.2,
    ]);
});

it('uses developer role for instructions when enabled', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    $instructions = 'Test instructions';
    $agent->withInstructions($instructions, true); // Enable developer role

    // Add a message to trigger instruction injection
    $driver->addMockResponse('stop', [
        'content' => 'Test response',
    ]);
    $agent->withMessage(Message::user('Test message'));
    $agent->run();

    $history = $chatHistory->toArray();
    $hasDevMessage = false;
    foreach ($history as $message) {
        if ($message['role'] === 'developer' && $message['content'] === $instructions) {
            $hasDevMessage = true;
            break;
        }
    }
    expect($hasDevMessage)->toBeTrue();
});

it('can stream messages using generator', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    // Prepare a mock response
    $driver->addMockResponse('stop', [
        'content' => 'This is a streaming response',
        'metaData' => [
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ],
    ]);

    // Set the message
    $agent->withMessage(Message::user('Test message'));

    // Run with streaming enabled
    $messages = [];
    $stream = $agent->runStreamed();

    foreach ($stream as $message) {
        $messages[] = $message;
    }

    expect($messages)->not->toBeEmpty();
    $lastMessage = end($messages);
    expect($lastMessage->getContentAsString())->toBe('This is a streaming response');
});

it('can enable streaming mode and process streamed responses', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);

    // Prepare a mock response
    $driver->addMockResponse('stop', [
        'content' => 'This is a streaming response',
        'metaData' => [
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ],
    ]);

    // Set the message
    $userMessage = Message::user('Test message');
    $agent->withMessage($userMessage);

    // Enable streaming with a callback
    $receivedChunks = [];
    $stream = $agent->runStreamed(function ($chunk) use (&$receivedChunks) {
        $receivedChunks[] = $chunk;
    });

    // Verify the response is a Generator
    expect($stream)->toBeInstanceOf(\Generator::class);

    // Collect all messages from the stream
    $messages = [];
    foreach ($stream as $message) {
        $messages[] = $message;
    }

    // Verify we received at least one message
    expect($messages)->not->toBeEmpty();

    // Verify the callback was called
    expect($receivedChunks)->not->toBeEmpty();

    // Verify the last message contains the expected content
    $lastMessage = end($messages);
    expect($lastMessage->getContentAsString())->toBe('This is a streaming response');

    // Verify the message was added to chat history
    $historyMessages = $chatHistory->getMessages();
    expect($historyMessages->count())->toBe(2); // User message + assistant response
    expect($historyMessages->last()->getContentAsString())->toBe('This is a streaming response');
});

it('can set and get arbitrary configs', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);
    $agent->setConfig('test_key', 'test_value');
    expect($agent->getConfig('test_key'))->toBe('test_value');
});

it('can chain arbitrary configs', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);
    $agent->withConfigs(['test_key' => 'test_value']);
    $agent->withConfigs(['test_key2' => 'test_value2']);
    expect($agent->getConfig('test_key'))->toBe('test_value');
    expect($agent->getConfig('test_key2'))->toBe('test_value2');
});

it('arbitrary configs are overwritten during chaining', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory);
    $agent->withConfigs(['test_key' => 'test_value']);
    $agent->withConfigs(['test_key' => 'test_value2']);
    expect($agent->getConfig('test_key'))->toBe('test_value2');
});

it('arbitrary configs are only ones included in configs', function () {
    $driver = new FakeLlmDriver;
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-session'));
    $agent = LarAgent::setup($driver, $chatHistory, [
        'test_key' => 'test_value',
        'temperature' => 0.5,
    ]);

    expect($agent->getConfigs())->toHaveKey('test_key');
    expect($agent->getConfig('test_key'))->toBe('test_value');
    expect($agent->getConfigs())->not->toHaveKey('temperature');
});
