<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testChatHistoryPath = app_path('AgentChatHistories/TestChatHistory.php');
    $this->customChatHistoryPath = app_path('AgentChatHistories/CustomChatHistory.php');
    $this->chatHistoriesDir = dirname($this->testChatHistoryPath);

    // Clean up any existing test files
    if (File::exists($this->testChatHistoryPath)) {
        unlink($this->testChatHistoryPath);
    }
    if (File::exists($this->customChatHistoryPath)) {
        unlink($this->customChatHistoryPath);
    }
});

afterEach(function () {
    // Clean up after tests
    if (File::exists($this->testChatHistoryPath)) {
        unlink($this->testChatHistoryPath);
    }
    if (File::exists($this->customChatHistoryPath)) {
        unlink($this->customChatHistoryPath);
    }

    // Only remove directory if it's empty (contains only . and .. entries)
    if (is_dir($this->chatHistoriesDir)) {
        $files = array_diff(scandir($this->chatHistoriesDir), ['.', '..']);
        if (empty($files)) {
            rmdir($this->chatHistoriesDir);
        }
    }
});

test('it can create a chat history class', function () {
    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertSuccessful()
        ->expectsOutput('Chat history created successfully: TestChatHistory')
        ->expectsOutput('Location: '.$this->testChatHistoryPath);

    expect(File::exists($this->testChatHistoryPath))->toBeTrue();

    $content = File::get($this->testChatHistoryPath);
    expect($content)
        ->toContain('class TestChatHistory extends ChatHistory')
        ->toContain('implements ChatHistoryInterface')
        ->toContain('public function readFromMemory(): void')
        ->toContain('public function writeToMemory(): void')
        ->toContain('public function saveKeyToMemory(): void')
        ->toContain('public function loadKeysFromMemory(): array')
        ->toContain('public function removeChatFromMemory(string $key): void')
        ->toContain('protected function removeChatKey(string $key): void');
});

test('it creates the AgentChatHistories directory if it doesn\'t exist', function () {
    $chatHistoriesDir = app_path('AgentChatHistories');

    expect(is_dir($chatHistoriesDir))->toBeFalse();

    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertSuccessful();

    expect(is_dir($chatHistoriesDir))->toBeTrue();
});

test('it fails when chat history already exists', function () {
    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertSuccessful();

    // Second creation should fail
    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertFailed()
        ->expectsOutput('Chat history already exists: TestChatHistory');
});

test('it creates chat history with proper imports and structure', function () {
    $this->artisan('make:agent:chat-history', ['name' => 'CustomChatHistory'])
        ->assertSuccessful();

    $content = File::get(app_path('AgentChatHistories/CustomChatHistory.php'));

    expect($content)
        ->toContain('use LarAgent\Core\Abstractions\ChatHistory;')
        ->toContain('use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;')
        ->toContain('class CustomChatHistory extends ChatHistory implements ChatHistoryInterface');
});

test('it handles different naming conventions correctly', function () {
    $testCases = [
        'DatabaseChatHistory',
        'RedisChatHistory',
        'FileSystemChatHistory',
        'CustomStorageChatHistory',
    ];

    foreach ($testCases as $name) {
        $path = app_path('AgentChatHistories/'.$name.'.php');

        $this->artisan('make:agent:chat-history', ['name' => $name])
            ->assertSuccessful();

        expect(File::exists($path))->toBeTrue();

        $content = File::get($path);
        expect($content)->toContain("class {$name} extends ChatHistory");

        unlink($path);
    }
});

test('it returns proper exit codes', function () {
    // It returns 0(success) as we are running this first time
    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertExitCode(0);

    // It returns 1(failure) as the chat history already exists
    $this->artisan('make:agent:chat-history', ['name' => 'TestChatHistory'])
        ->assertExitCode(1);
});
