<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testToolPath = app_path('AgentTools/TestTool.php');
    $this->customToolPath = app_path('AgentTools/CustomTool.php');
    $this->toolsDir = dirname($this->testToolPath);

    // Clean up any existing test files
    if (File::exists($this->testToolPath)) {
        unlink($this->testToolPath);
    }
    if (File::exists($this->customToolPath)) {
        unlink($this->customToolPath);
    }
});

afterEach(function () {
    // Clean up after tests
    if (File::exists($this->testToolPath)) {
        unlink($this->testToolPath);
    }
    if (File::exists($this->customToolPath)) {
        unlink($this->customToolPath);
    }

    // Only remove directory if it's empty (contains only . and .. entries)
    if (is_dir($this->toolsDir)) {
        $files = array_diff(scandir($this->toolsDir), ['.', '..']);
        if (empty($files)) {
            rmdir($this->toolsDir);
        }
    }
});

test('it can create a tool class', function () {
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful()
        ->expectsOutput('Tool created successfully: TestTool')
        ->expectsOutput('Location: '.$this->testToolPath);

    expect(File::exists($this->testToolPath))->toBeTrue();

    $content = File::get($this->testToolPath);
    expect($content)
        ->toContain('class TestTool extends Tool')
        ->toContain('protected string $name')
        ->toContain('protected string $description')
        ->toContain('protected array $properties')
        ->toContain('protected array $required')
        ->toContain('protected array $metaData')
        ->toContain('public function execute(array $input): mixed');
});

test('it creates the AgentTools directory if it doesn\'t exist', function () {
    $toolsDir = app_path('AgentTools');

    expect(is_dir($toolsDir))->toBeFalse();

    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful();

    expect(is_dir($toolsDir))->toBeTrue();
});

test('it fails when tool already exists', function () {
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertSuccessful();

    // Second creation should fail
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertFailed()
        ->expectsOutput('Tool already exists: TestTool');
});

test('it creates tool with proper imports and structure', function () {
    $this->artisan('make:agent:tool', ['name' => 'CustomTool'])
        ->assertSuccessful();

    $content = File::get(app_path('AgentTools/CustomTool.php'));

    expect($content)
        ->toContain('use LarAgent\Core\Abstractions\Tool;')
        ->toContain('class CustomTool extends Tool')
        ->toContain('namespace App\AgentTools');
});

test('it handles different naming conventions correctly', function () {
    $testCases = [
        'WeatherTool',
        'DatabaseTool',
        'ApiTool',
        'CustomServiceTool',
    ];

    foreach ($testCases as $name) {
        $path = app_path('AgentTools/'.$name.'.php');

        $this->artisan('make:agent:tool', ['name' => $name])
            ->assertSuccessful();

        expect(File::exists($path))->toBeTrue();

        $content = File::get($path);
        expect($content)->toContain("class {$name} extends Tool");

        unlink($path);
    }
});

test('it returns proper exit codes', function () {
    // It returns 0(success) as we are running this first time
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertExitCode(0);

    // It returns 1(failure) as the tool already exists
    $this->artisan('make:agent:tool', ['name' => 'TestTool'])
        ->assertExitCode(1);
});
