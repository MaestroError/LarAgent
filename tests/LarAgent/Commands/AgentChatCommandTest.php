<?php

use LarAgent\Agent;

beforeEach(function () {
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
        ->expectsQuestion('You', 'exit')
        ->expectsOutput('Chat ended')
        ->assertExitCode(0);
});
