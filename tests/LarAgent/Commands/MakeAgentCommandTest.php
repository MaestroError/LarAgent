<?php

use Illuminate\Support\Facades\File;
use LarAgent\Commands\MakeAgentCommand;

beforeEach(function () {
    $this->testAgentPath = app_path('AiAgents/TestAgent.php');

    // Clean up any existing test files
    if (File::exists($this->testAgentPath)) {
        unlink($this->testAgentPath);
    }

    if (is_dir(dirname($this->testAgentPath))) {
        rmdir(dirname($this->testAgentPath));
    }
});

afterEach(function () {
    // Clean up after tests
    if (File::exists($this->testAgentPath)) {
        unlink($this->testAgentPath);
    }

    if (is_dir(dirname($this->testAgentPath))) {
        rmdir(dirname($this->testAgentPath));
    }
});

test('it can create an agent', function () {
    $command = new MakeAgentCommand;

    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertSuccessful()
        ->expectsOutput('Agent created successfully: TestAgent')
        ->expectsOutput('Location: '.$this->testAgentPath);

    expect(File::exists($this->testAgentPath))->toBeTrue();

    $content = File::get($this->testAgentPath);
    expect($content)
        ->toContain('namespace App\AiAgents')
        ->toContain('class TestAgent extends Agent')
        ->toContain('protected $model = \'gpt-4\'')
        ->toContain('protected $history = \'in_memory\'')
        ->toContain('protected $provider = \'default\'');
});

test('it creates the AiAgents directory if it doesn\'t exist', function () {
    $aiAgentsDir = app_path('AiAgents');

    expect(is_dir($aiAgentsDir))->toBeFalse();

    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertSuccessful();

    expect(is_dir($aiAgentsDir))->toBeTrue();
});

test('it fails when agent already exists', function () {
    // First creation should succeed
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertSuccessful();

    // Second creation should fail
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertFailed()
        ->expectsOutput('Agent already exists: TestAgent');
});
