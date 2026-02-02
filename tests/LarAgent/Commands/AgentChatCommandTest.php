<?php

use Illuminate\Support\Facades\Session;
use LarAgent\Agent;

beforeEach(function () {
    // Clear session to ensure test isolation
    Session::flush();

    // Create a mock agent class file
    if (! is_dir(app_path('AiAgents'))) {
        mkdir(app_path('AiAgents'), 0755, true);
    }

    $agentContent = <<<'PHP'
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

class TestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $provider = 'default';
    protected $tools = [];
    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return "Test agent instructions";
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Test response',
        ]);
    }
}
PHP;

    file_put_contents(app_path('AiAgents/TestAgent.php'), $agentContent);

    // Make sure the autoloader can find our test agent
    require_once app_path('AiAgents/TestAgent.php');
});

afterEach(function () {
    // Clean up the test agent
    if (file_exists(app_path('AiAgents/TestAgent.php'))) {
        unlink(app_path('AiAgents/TestAgent.php'));
    }

    if (is_dir(app_path('AiAgents')) && count(scandir(app_path('AiAgents'))) <= 2) {
        rmdir(app_path('AiAgents'));
    }
});

test('it fails when agent does not exist', function () {
    $this->artisan('agent:chat', ['agent' => 'NonExistentAgent'])
        ->assertFailed()
        ->expectsOutput('Agent not found: NonExistentAgent');
});

test('it can start chat with existing agent', function () {
    $this->artisan('agent:chat', ['agent' => 'TestAgent'])
        ->expectsOutput('Starting chat with TestAgent')
        ->expectsQuestion('You', 'exit')
        ->expectsOutput('Chat ended')
        ->assertExitCode(0);
});

test('it uses provided history name', function () {
    $this->artisan('agent:chat', [
        'agent' => 'TestAgent',
        '--history' => 'test_history',
    ])
        ->expectsOutput('Using history: test_history')
        ->expectsQuestion('You', 'exit')
        ->expectsOutput('Chat ended')
        ->assertExitCode(0);
});

test('it can handle multiple messages', function () {
    $this->artisan('agent:chat', ['agent' => 'TestAgent'])
        ->expectsOutput('Starting chat with TestAgent')
        ->expectsQuestion('You', 'Hello')
        ->expectsOutputToContain('Test response')
        ->expectsOutputToContain('Response completed in')
        ->expectsQuestion('You', 'exit')
        ->expectsOutput('Chat ended')
        ->assertExitCode(0);
});

test('it displays tool calls when agent uses tools', function () {
    // Create an agent with tool calls
    $agentContent = <<<'PHP'
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\PhantomTool;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

class TestToolAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $provider = 'default';
    protected $driver = FakeLlmDriver::class;

    public function registerTools()
    {
        return [
            PhantomTool::create('test_tool', 'A test tool')->setCallback(fn () => 'tool result'),
        ];
    }

    public function instructions()
    {
        return "Test agent instructions";
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'test_tool',
            'arguments' => '{}',
        ]);
    }
}
PHP;

    file_put_contents(app_path('AiAgents/TestToolAgent.php'), $agentContent);
    require_once app_path('AiAgents/TestToolAgent.php');

    $this->artisan('agent:chat', ['agent' => 'TestToolAgent'])
        ->expectsOutput('Starting chat with TestToolAgent')
        ->expectsQuestion('You', 'Use a tool')
        ->expectsOutput('Tool call: test_tool')
        ->expectsOutputToContain('Response completed in')
        ->expectsQuestion('You', 'exit')
        ->expectsOutput('Chat ended')
        ->assertExitCode(0);

    // Clean up
    if (file_exists(app_path('AiAgents/TestToolAgent.php'))) {
        unlink(app_path('AiAgents/TestToolAgent.php'));
    }
});

test('formatElapsedTime formats seconds correctly', function () {
    $command = new \LarAgent\Commands\AgentChatCommand;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('formatElapsedTime');
    $method->setAccessible(true);

    // Test time under 1 second (should show milliseconds)
    expect($method->invoke($command, 0.5))->toBe('500 ms');
    expect($method->invoke($command, 0.123))->toBe('123 ms');
    expect($method->invoke($command, 0.0567))->toBe('57 ms');

    // Test time equal to or over 1 second (should show seconds)
    expect($method->invoke($command, 1.0))->toBe('1.00 seconds');
    expect($method->invoke($command, 1.5))->toBe('1.50 seconds');
    expect($method->invoke($command, 2.345))->toBe('2.35 seconds');
    expect($method->invoke($command, 10.999))->toBe('11.00 seconds');
});
