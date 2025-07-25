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
    $this->artisan('agent:chat:clear', ['agent' => 'NonExistentAgent'])
        ->assertFailed()
        ->expectsOutput('Agent not found: NonExistentAgent');
});

test('it can clear chat history for existing agent', function () {
    // Create some chat history first
    $agent = \App\AiAgents\TestAgent::for('test_key');
    $agent->withModelInChatSessionId()->message('Hello')->respond();

    // Verify chat history exists
    $chatKeys = $agent->getChatKeys();
    expect($chatKeys)->toHaveCount(1);
    expect($chatKeys)->toContain('TestAgent_gpt-4o-mini_test_key');

    // Clear the history
    $this->artisan('agent:chat:clear', ['agent' => 'TestAgent'])
        ->assertSuccessful()
        ->expectsOutput('Successfully cleared chat history for agent: TestAgent');

    // Verify all chat histories are cleared but keys remain
    $agent = \App\AiAgents\TestAgent::for('test_key');
    expect($agent->chatHistory()->getMessages())->toBeEmpty();
    expect($agent->getChatKeys())->toHaveCount(1);
});
