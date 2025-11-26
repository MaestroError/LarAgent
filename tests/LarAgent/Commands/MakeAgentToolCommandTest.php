<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testToolPath = app_path('AgentTools/TestTool.php');

    // Clean up any existing test files
    if (File::exists($this->testToolPath)) {
        unlink($this->testToolPath);
    }

    if (is_dir(dirname($this->testToolPath))) {
        File::deleteDirectory(dirname($this->testToolPath));
    }
});

afterEach(function () {
    // Clean up after tests
    if (File::exists($this->testToolPath)) {
        unlink($this->testToolPath);
    }

    if (is_dir(dirname($this->testToolPath))) {
        File::deleteDirectory(dirname($this->testToolPath));
    }
});

test('it can create an agent tool', function () {
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful()
        ->expectsOutput('Agent tool created successfully: TestTool')
        ->expectsOutput('Location: '.$this->testToolPath);

    expect(File::exists($this->testToolPath))->toBeTrue();

    $content = File::get($this->testToolPath);
    expect($content)
        ->toContain('namespace App\AgentTools')
        ->toContain('class TestTool extends Tool')
        ->toContain('protected string $name = \'test_tool\'')
        ->toContain('public function execute(array $input): mixed');
});

test('it creates the AgentTools directory if it doesn\'t exist', function () {
    $agentToolsDir = app_path('AgentTools');

    expect(is_dir($agentToolsDir))->toBeFalse();

    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful();

    expect(is_dir($agentToolsDir))->toBeTrue();
});

test('it fails when agent tool already exists', function () {
    // First creation should succeed
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful();

    // Second creation should fail
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertFailed()
        ->expectsOutput('Agent tool already exists: TestTool');
});

test('it converts tool name to snake_case in the name property', function () {
    $this->artisan('make:agent:tool', ['name' => 'WeatherTool'])
        ->assertSuccessful();

    $weatherToolPath = app_path('AgentTools/WeatherTool.php');
    $content = File::get($weatherToolPath);
    expect($content)->toContain("protected string \$name = 'weather_tool'");

    // Clean up
    unlink($weatherToolPath);
});
