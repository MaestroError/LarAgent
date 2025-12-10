<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LarAgent\Agent;
use LarAgent\Context\Drivers\EloquentStorage;
use LarAgent\Context\Drivers\FileStorage;
use LarAgent\Context\Drivers\SimpleEloquentStorage;
use LarAgent\Context\Models\LaragentMessage;
use LarAgent\Context\Models\LaragentStorage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use ReflectionClass;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run migrations for both storage types
    $simpleEloquentMigration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_storage_table.php';
    $simpleEloquentMigration->up();

    $eloquentMigration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_messages_table.php';
    $eloquentMigration->up();
});

afterEach(function () {
    // Clean up
    $eloquentMigration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_messages_table.php';
    $eloquentMigration->down();

    $simpleEloquentMigration = include __DIR__.'/../../../src/Context/Database/migrations/create_laragent_storage_table.php';
    $simpleEloquentMigration->down();
});

// Test agent using 'database-simple' built-in driver (string)
class DatabaseSimpleBuiltInAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'database-simple';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a test agent using database-simple storage.';
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello from database-simple storage!',
        ]);
    }
}

// Test agent using 'database' built-in driver (string)
class DatabaseBuiltInAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'database';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a test agent using database storage.';
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello from database storage!',
        ]);
    }
}

// Test agent overriding historyStorageDrivers with custom EloquentStorage instance
class CustomEloquentStorageAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a test agent with custom EloquentStorage.';
    }

    protected function historyStorageDrivers(): string|array
    {
        // Return EloquentStorage instance with custom model wrapped in array
        return [new EloquentStorage(LaragentMessage::class)];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello from custom EloquentStorage!',
        ]);
    }
}

// Test agent overriding historyStorageDrivers with custom SimpleEloquentStorage instance
class CustomSimpleEloquentStorageAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a test agent with custom SimpleEloquentStorage.';
    }

    protected function historyStorageDrivers(): string|array
    {
        // Return SimpleEloquentStorage instance with custom model wrapped in array
        return [new SimpleEloquentStorage(LaragentStorage::class)];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello from custom SimpleEloquentStorage!',
        ]);
    }
}

// Test agent using multiple storage drivers together: File + SimpleEloquent + Eloquent
class MultiDriverStorageAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $driver = FakeLlmDriver::class;

    protected static string $testStorageFolder;

    public function instructions()
    {
        return 'You are a test agent with multiple storage drivers.';
    }

    public static function setTestStoragePath(string $folder): void
    {
        self::$testStorageFolder = $folder;
    }

    protected function historyStorageDrivers(): string|array
    {
        // Return multiple drivers: FileStorage (primary), SimpleEloquentStorage, EloquentStorage
        // Primary driver is used for reading, all drivers are written to
        // FileStorage constructor: (disk, folder) - using default disk with custom folder
        return [
            new FileStorage(null, self::$testStorageFolder),
            new SimpleEloquentStorage(LaragentStorage::class),
            new EloquentStorage(LaragentMessage::class),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello from multi-driver storage!',
        ]);
    }
}

describe('Agent with database-simple built-in driver', function () {

    it('can use database-simple as built-in history driver string', function () {
        $agent = DatabaseSimpleBuiltInAgent::for('test-session');

        expect($agent->chatHistory())->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);

        $response = $agent->respond('Hello');

        expect($response)->toBe('Hello from database-simple storage!');
    });

    it('persists chat history to database using SimpleEloquentStorage', function () {
        $agent = DatabaseSimpleBuiltInAgent::for('persist-test');

        $agent->respond('Test message');

        // Save the chat history
        $agent->chatHistory()->save();

        // Check that data exists in the database
        expect(LaragentStorage::count())->toBeGreaterThan(0);

        // Create a new agent with same session and verify history is loaded
        $agent2 = DatabaseSimpleBuiltInAgent::for('persist-test');
        $agent2->chatHistory()->read();

        expect($agent2->chatHistory()->count())->toBeGreaterThan(0);
    });

    it('isolates chat history between different sessions', function () {
        $agent1 = DatabaseSimpleBuiltInAgent::for('session-1');
        $agent2 = DatabaseSimpleBuiltInAgent::for('session-2');

        $agent1->respond('Message for session 1');
        $agent1->chatHistory()->save();

        $agent2->respond('Message for session 2');
        $agent2->chatHistory()->save();

        // Verify isolation by reloading and checking content
        $agent1Reloaded = DatabaseSimpleBuiltInAgent::for('session-1');
        $agent1Reloaded->chatHistory()->read();

        $agent2Reloaded = DatabaseSimpleBuiltInAgent::for('session-2');
        $agent2Reloaded->chatHistory()->read();

        // Get user messages from each session
        $agent1Messages = $agent1Reloaded->chatHistory()->getMessages();
        $agent2Messages = $agent2Reloaded->chatHistory()->getMessages();

        // Find user messages and verify they contain the correct content
        $agent1UserContent = null;
        $agent2UserContent = null;

        foreach ($agent1Messages as $msg) {
            if ($msg->getRole() === 'user') {
                $agent1UserContent = (string) $msg->getContent();
                break;
            }
        }

        foreach ($agent2Messages as $msg) {
            if ($msg->getRole() === 'user') {
                $agent2UserContent = (string) $msg->getContent();
                break;
            }
        }

        // Verify each session has its own unique message
        expect($agent1UserContent)->toContain('session 1');
        expect($agent2UserContent)->toContain('session 2');
        expect($agent1UserContent)->not->toContain('session 2');
        expect($agent2UserContent)->not->toContain('session 1');
    });
});

describe('Agent with database built-in driver', function () {

    it('can use database as built-in history driver string', function () {
        $agent = DatabaseBuiltInAgent::for('test-session');

        expect($agent->chatHistory())->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);

        $response = $agent->respond('Hello');

        expect($response)->toBe('Hello from database storage!');
    });

    it('persists chat history to database using EloquentStorage', function () {
        $agent = DatabaseBuiltInAgent::for('persist-test-eloquent');

        $agent->respond('Test message');

        // Save the chat history
        $agent->chatHistory()->save();

        // Check that messages exist in the database
        expect(LaragentMessage::count())->toBeGreaterThan(0);

        // Create a new agent with same session and verify history is loaded
        $agent2 = DatabaseBuiltInAgent::for('persist-test-eloquent');
        $agent2->chatHistory()->read();

        expect($agent2->chatHistory()->count())->toBeGreaterThan(0);
    });

    it('stores messages as individual rows with position', function () {
        $agent = DatabaseBuiltInAgent::for('position-test');

        $agent->respond('Test message');
        $agent->chatHistory()->save();

        // Verify multiple rows exist (system + user + assistant at minimum)
        $messages = LaragentMessage::where('session_key', 'like', '%position-test%')
            ->orderBy('position')
            ->get();

        expect($messages->count())->toBeGreaterThanOrEqual(2);

        // Verify position column is properly set
        foreach ($messages as $index => $message) {
            expect($message->position)->toBe($index);
        }
    });
});

describe('Agent with custom EloquentStorage instance via historyStorageDrivers override', function () {

    it('can use custom EloquentStorage instance', function () {
        $agent = CustomEloquentStorageAgent::for('custom-eloquent-test');

        expect($agent->chatHistory())->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);

        $response = $agent->respond('Hello');

        expect($response)->toBe('Hello from custom EloquentStorage!');
    });

    it('persists chat history using custom EloquentStorage', function () {
        $agent = CustomEloquentStorageAgent::for('custom-persist-test');

        $agent->respond('Custom storage message');
        $agent->chatHistory()->save();

        // Verify data is stored
        expect(LaragentMessage::count())->toBeGreaterThan(0);

        // Reload and verify
        $agent2 = CustomEloquentStorageAgent::for('custom-persist-test');
        $agent2->chatHistory()->read();

        expect($agent2->chatHistory()->count())->toBeGreaterThan(0);
    });
});

describe('Agent with custom SimpleEloquentStorage instance via historyStorageDrivers override', function () {

    it('can use custom SimpleEloquentStorage instance', function () {
        $agent = CustomSimpleEloquentStorageAgent::for('custom-simple-test');

        expect($agent->chatHistory())->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);

        $response = $agent->respond('Hello');

        expect($response)->toBe('Hello from custom SimpleEloquentStorage!');
    });

    it('persists chat history using custom SimpleEloquentStorage', function () {
        $agent = CustomSimpleEloquentStorageAgent::for('custom-simple-persist');

        $agent->respond('Custom simple storage message');
        $agent->chatHistory()->save();

        // Verify data is stored
        expect(LaragentStorage::count())->toBeGreaterThan(0);

        // Reload and verify
        $agent2 = CustomSimpleEloquentStorageAgent::for('custom-simple-persist');
        $agent2->chatHistory()->read();

        expect($agent2->chatHistory()->count())->toBeGreaterThan(0);
    });
});

describe('Built-in driver resolution', function () {

    it('resolves database-simple string to SimpleEloquentStorage class', function () {
        // Use reflection to access builtInHistories from parent Agent class
        $agent = DatabaseSimpleBuiltInAgent::for('test');
        $reflection = new ReflectionClass(Agent::class);
        $property = $reflection->getProperty('builtInHistories');
        $property->setAccessible(true);
        $builtInHistories = $property->getValue($agent);

        expect($builtInHistories)->toHaveKey('database-simple');
        expect($builtInHistories['database-simple'])->toBe(SimpleEloquentStorage::class);
    });

    it('resolves database string to EloquentStorage class', function () {
        // Use reflection to access builtInHistories from parent Agent class
        $agent = DatabaseBuiltInAgent::for('test');
        $reflection = new ReflectionClass(Agent::class);
        $property = $reflection->getProperty('builtInHistories');
        $property->setAccessible(true);
        $builtInHistories = $property->getValue($agent);

        expect($builtInHistories)->toHaveKey('database');
        expect($builtInHistories['database'])->toBe(EloquentStorage::class);
    });
});

describe('Agent with multiple storage drivers (File + SimpleEloquent + Eloquent)', function () {

    beforeEach(function () {
        // Use Laravel's default storage for file storage tests
        $this->testFolder = 'laragent_multi_driver_test_'.uniqid();
    });

    afterEach(function () {
        // Clean up file storage using Laravel's Storage facade
        if (isset($this->testFolder)) {
            \Illuminate\Support\Facades\Storage::deleteDirectory($this->testFolder);
        }
    });

    it('can use multiple storage drivers together', function () {
        MultiDriverStorageAgent::setTestStoragePath($this->testFolder);
        $agent = MultiDriverStorageAgent::for('multi-driver-test');

        expect($agent->chatHistory())->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);

        $response = $agent->respond('Hello');

        expect($response)->toBe('Hello from multi-driver storage!');
    });

    it('writes to all storage drivers simultaneously', function () {
        MultiDriverStorageAgent::setTestStoragePath($this->testFolder);
        $agent = MultiDriverStorageAgent::for('multi-write-test');

        $agent->respond('Multi-driver message');
        $agent->chatHistory()->save();

        // Verify data exists in all three storage backends

        // 1. Check FileStorage - files should exist in the storage folder
        $files = \Illuminate\Support\Facades\Storage::files($this->testFolder);
        expect(count($files))->toBeGreaterThan(0);

        // 2. Check SimpleEloquentStorage - data should be in laragent_storage table
        expect(LaragentStorage::count())->toBeGreaterThan(0);

        // 3. Check EloquentStorage - messages should be in laragent_messages table
        expect(LaragentMessage::count())->toBeGreaterThan(0);
    });

    it('reads from primary driver (FileStorage) by default', function () {
        MultiDriverStorageAgent::setTestStoragePath($this->testFolder);
        $agent = MultiDriverStorageAgent::for('primary-read-test');

        $agent->respond('Primary driver message');
        $agent->chatHistory()->save();

        // Get the count from original agent
        $originalCount = $agent->chatHistory()->count();

        // Create new agent and read - should read from primary (FileStorage)
        MultiDriverStorageAgent::setTestStoragePath($this->testFolder);
        $agent2 = MultiDriverStorageAgent::for('primary-read-test');
        $agent2->chatHistory()->read();

        expect($agent2->chatHistory()->count())->toBe($originalCount);

        // Verify the user message content is correct
        $messages = $agent2->chatHistory()->getMessages();
        $userMessageFound = false;
        foreach ($messages as $msg) {
            if ($msg->getRole() === 'user') {
                expect((string) $msg->getContent())->toContain('Primary driver message');
                $userMessageFound = true;
                break;
            }
        }
        expect($userMessageFound)->toBeTrue();
    });

    it('maintains data consistency across all drivers', function () {
        MultiDriverStorageAgent::setTestStoragePath($this->testFolder);
        $agent = MultiDriverStorageAgent::for('consistency-test');

        $agent->respond('Consistency test message');
        $agent->chatHistory()->save();

        // Get messages count from the agent
        $expectedCount = $agent->chatHistory()->count();

        // Verify SimpleEloquentStorage has the same data
        $simpleRecord = LaragentStorage::where('key', 'like', '%consistency-test%')->first();
        expect($simpleRecord)->not->toBeNull();
        expect(count($simpleRecord->data))->toBe($expectedCount);

        // Verify EloquentStorage has the same number of messages
        $eloquentMessages = LaragentMessage::where('session_key', 'like', '%consistency-test%')->get();
        expect($eloquentMessages->count())->toBe($expectedCount);
    });
});
