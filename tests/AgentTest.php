<?php

use Illuminate\Contracts\Auth\Authenticatable;
use LarAgent\Agent;
use LarAgent\Message;
use LarAgent\Tests\Fakes\FakeLlmDriver;
use LarAgent\Tool;

// Test agent
class TestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = FakeLlmDriver::class;

    public $saveToolResult = null;

    public function instructions()
    {
        return 'You are a test agent.';
    }

    public function prompt($message)
    {
        return $message.' Please respond appropriately.';
    }

    public function registerTools()
    {
        return [
            Tool::create('test_tool', 'A tool for testing')
                ->addProperty('input', 'string', 'Input for the tool')
                ->setRequired('input')
                ->setCallback(function ($input) {
                    return 'Processed '.$input;
                }),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'test_tool',
            'arguments' => json_encode(['input' => 'test input']),
        ]);

        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Processed test input',
        ]);
    }

    protected function afterResponse($message)
    {
        $message->setContent($message.'. Edited via event');
    }

    protected function afterToolExecution($tool, &$result)
    {
        $this->saveToolResult = $result;
    }
}

// Test tool
class WeatherTool extends Tool
{
    protected string $name = 'get_current_weather';

    protected string $description = 'Get the current weather in a given location';

    protected array $properties = [
        'location' => [
            'type' => 'string',
            'description' => 'The city and state, e.g. San Francisco, CA',
        ],
        'unit' => [
            'type' => 'string',
            'description' => 'The unit of temperature',
            'enum' => ['celsius', 'fahrenheit'],
        ],
    ];

    protected array $required = ['location'];

    protected array $metaData = ['sent_at' => '2024-01-01'];

    public function execute(array $input): mixed
    {
        // Call the weather API
        return 'The weather in '.$input['location'].' is '.rand(10, 60).' degrees '.$input['unit'];
    }
}

it('can create an agent for a user', function () {
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn('user_123');

    $agent = TestAgent::forUser($user);

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getChatSessionId())->toContain('user_123');
});

it('can create an agent with a specific key', function () {
    $agent = TestAgent::for('test_key');

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getChatSessionId())->toContain('test_key');
});

it('can set and get message', function () {
    $agent = TestAgent::for('test_key');
    $message = 'Hello, Agent!';
    $agent->respond($message);

    expect($agent->currentMessage())->toBe($message);
});

it('can use tools and respond', function () {
    $agent = TestAgent::for('test_key');

    $response = $agent->respond('Use the test tool with input "test input".');

    expect($response)->toBe('Processed test input. Edited via event');
    expect($agent->saveToolResult)->toBe('Processed test input');
});

it('can handle events', function () {
    $agent = TestAgent::for('test_key');
    $agent->respond('test');
    $message = $agent->lastMessage();

    // Check if "afterResponse" event worked
    expect((string) $message)->toContain('Edited via event');
});

it('can handle image urls in response', function () {
    $agent = new TestAgent('test_session');
    $agent->withImages([
        'http://example.com/image1.jpg',
        'http://example.com/image2.jpg',
    ]);

    $message = $agent->message('Test message')->respond();

    expect($message)->toBe('Processed test input. Edited via event');

    // Get the last message from chat history to verify images
    $messages = $agent->chatHistory()->getMessages();
    $firstUserMessage = $messages[1];

    expect($firstUserMessage->getContent())->toBeArray()
        ->and($firstUserMessage->getContent())->toHaveCount(3) // text + 2 images
        ->and($firstUserMessage->getContent()[0])->toMatchArray([
            'type' => 'text',
            'text' => 'Test message Please respond appropriately.',
        ])
        ->and($firstUserMessage->getContent()[1])->toMatchArray([
            'type' => 'image_url',
            'image_url' => ['url' => 'http://example.com/image1.jpg'],
        ])
        ->and($firstUserMessage->getContent()[2])->toMatchArray([
            'type' => 'image_url',
            'image_url' => ['url' => 'http://example.com/image2.jpg'],
        ]);
});

it('can dynamically change model', function () {
    $agent = new TestAgent('test_session');

    // Check default model
    expect($agent->model())->toBe('gpt-4o-mini');

    // Change model dynamically
    $agent->withModel('gpt-3.5-turbo');

    // Verify model was changed
    expect($agent->model())->toBe('gpt-3.5-turbo');

    // Verify chainable method returns agent instance
    expect($agent->withModel('gpt-4'))->toBeInstanceOf(Agent::class);
});

it('can get chat keys filtered by agent class', function () {
    // Create a few chat sessions with different agents and models
    $agent1 = TestAgent::for('user1');
    $agent1->withModel('gpt-4')->respond('First message from user1');

    // Create a different agent class to ensure filtering works
    class AnotherAgent extends TestAgent {}
    $otherAgent = AnotherAgent::for('user3');
    $otherAgent->respond('Message from other agent');

    // Get chat keys for TestAgent
    $testAgentKeys = $agent1->getChatKeys();

    // Should contain TestAgent keys but not AnotherAgent keys
    expect($testAgentKeys)
        ->toBeArray()
        ->and($testAgentKeys)->toHaveCount(1)
        ->and($testAgentKeys)->toContain('TestAgent_gpt-4_user1')
        ->and($testAgentKeys)->not->toContain('AnotherAgent_gpt-4o-mini_user3');

    // Get chat keys for AnotherAgent
    $otherAgentKeys = $otherAgent->getChatKeys();

    // Should only contain AnotherAgent keys
    expect($otherAgentKeys)
        ->toBeArray()
        ->and($otherAgentKeys)->toHaveCount(1)
        ->and($otherAgentKeys)->toContain('AnotherAgent_gpt-4o-mini_user3')
        ->and($otherAgentKeys)->not->toContain('TestAgent_gpt-4_user1');
});

it('can add custom message to chat history', function () {
    $agent = TestAgent::for('test_key');
    $systemMessage = Message::system('Test system message');

    $agent->addMessage($systemMessage);
    $messages = $agent->chatHistory()->getMessages();

    expect($messages)->toContain($systemMessage);
});

it('excludes parallel_tool_calls from config when set to null', function () {
    $agent = TestAgent::for('test_session');
    $reflection = new ReflectionClass($agent);
    $parallelToolCalls = $reflection->getProperty('parallelToolCalls');
    $parallelToolCalls->setAccessible(true);
    $parallelToolCalls->setValue($agent, null);

    $tool = Tool::create('test_tool', 'Test tool')->setCallback(fn () => 'test');
    $agent->withTool($tool);

    $buildConfigsForLaragent = $reflection->getMethod('buildConfigsForLaragent');
    $buildConfigsForLaragent->setAccessible(true);
    $config = $buildConfigsForLaragent->invoke($agent);

    expect($config)->toHaveKey('parallelToolCalls')
        ->and($config['parallelToolCalls'])->toBeNull();
});

it('can add tool using class reference', function () {
    // Create a new agent instance
    $agent = TestAgent::for('test_session');
    
    // Get initial tools count
    $initialTools = $agent->getTools();
    $initialCount = count($initialTools);
    
    // Add tool using class reference
    $agent->withTool(WeatherTool::class);
    
    // Get updated tools
    $updatedTools = $agent->getTools();
    
    // Verify tool was added
    expect($updatedTools)->toHaveCount($initialCount + 1);
    
    // Check if the WeatherTool was added
    $weatherToolFound = false;
    foreach ($updatedTools as $tool) {
        if ($tool instanceof WeatherTool && 
            $tool->getName() === 'get_current_weather' &&
            $tool->getDescription() === 'Get the current weather in a given location') {
            $weatherToolFound = true;
            break;
        }
    }
    
    expect($weatherToolFound)->toBeTrue();
        
    // Verify method returns agent instance for chaining
    expect($agent->withTool(WeatherTool::class))->toBeInstanceOf(Agent::class);
});

it('can remove tool by name', function () {
    // Create a new agent instance with the weather tool
    $agent = TestAgent::for('test_session');
    $agent->withTool(WeatherTool::class);
    
    // Get initial tools count
    $initialTools = $agent->getTools();
    $initialCount = count($initialTools);
    
    // Remove tool by name
    $agent->removeTool('get_current_weather');
    
    // Get updated tools
    $updatedTools = $agent->getTools();
    
    // Verify tool was removed
    expect($updatedTools)->toHaveCount($initialCount - 1)
        ->and($updatedTools)->not->toContain(fn ($tool) => 
            $tool instanceof WeatherTool
        );
        
    // Verify method returns agent instance for chaining
    expect($agent->removeTool('test_tool'))->toBeInstanceOf(Agent::class);
});

it('can remove tool by class reference', function () {
    // Create a new agent instance with the weather tool
    $agent = TestAgent::for('test_session');
    $agent->withTool(WeatherTool::class);
    
    // Get initial tools count
    $initialTools = $agent->getTools();
    $initialCount = count($initialTools);
    
    // Remove tool by class reference
    $agent->removeTool(WeatherTool::class);
    
    // Get updated tools
    $updatedTools = $agent->getTools();
    
    // Verify tool was removed
    expect($updatedTools)->toHaveCount($initialCount - 1)
        ->and($updatedTools)->not->toContain(fn ($tool) => 
            $tool instanceof WeatherTool
        );
});

it('can remove tool by tool object', function () {
    // Create a new agent instance
    $agent = TestAgent::for('test_session');
    
    // Create a custom tool with a specific name
    $toolName = 'custom_tool';
    $customTool = Tool::create($toolName, 'A custom tool for testing')
        ->setCallback(fn ($param) => "Result: {$param}");
    
    // Add the custom tool
    $agent->withTool($customTool);
    
    // Get initial tools count
    $initialTools = $agent->getTools();
    $initialCount = count($initialTools);
    
    // Remove tool by tool object
    $agent->removeTool($customTool);
    
    // Get updated tools
    $updatedTools = $agent->getTools();
    
    // Verify tool was removed
    expect($updatedTools)->toHaveCount($initialCount - 1)
        ->and($updatedTools)->not->toContain(fn ($tool) => 
            $tool->getName() === 'custom_tool'
        );
});

it('uses developer role for instructions when enabled', function () {
    $agent = new TestAgent('test_session');

    $reflection = new ReflectionClass($agent);
    $parallelToolCalls = $reflection->getProperty('developerRoleForInstructions');
    $parallelToolCalls->setAccessible(true);
    $parallelToolCalls->setValue($agent, true);

    $agent->respond('Test message');

    $messages = $agent->chatHistory()->getMessages();
    $hasDevMessage = false;
    foreach ($messages as $message) {
        if ($message->getRole() === 'developer' && $message->getContent() === 'You are a test agent.') {
            $hasDevMessage = true;
            break;
        }
    }
    expect($hasDevMessage)->toBeTrue();
});
