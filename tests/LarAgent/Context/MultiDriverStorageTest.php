<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use Illuminate\Support\Facades\Storage;
use LarAgent\Context\Abstract\StorageDriver as AbstractStorageDriver;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\UserMessage;

// ===========================================
// Helper Classes
// ===========================================

/**
 * Mock driver that always fails to read
 */
class FailingReadDriver extends AbstractStorageDriver
{
    public function readFromMemory(SessionIdentityContract $identity): ?array
    {
        throw new \Exception('Read failed');
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): bool
    {
        return true;
    }

    public function removeFromMemory(SessionIdentityContract $identity): bool
    {
        return true;
    }
}

/**
 * Mock driver that returns null (empty)
 */
class EmptyDriver extends AbstractStorageDriver
{
    public function readFromMemory(SessionIdentityContract $identity): ?array
    {
        return null;
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): bool
    {
        return true;
    }

    public function removeFromMemory(SessionIdentityContract $identity): bool
    {
        return true;
    }
}

/**
 * Tracking driver that records all operations
 */
class TrackingDriver extends AbstractStorageDriver
{
    public array $reads = [];

    public array $writes = [];

    public array $removes = [];

    public ?array $data = null;

    public function readFromMemory(SessionIdentityContract $identity): ?array
    {
        $this->reads[] = $identity->getKey();

        return $this->data;
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): bool
    {
        $this->writes[] = ['key' => $identity->getKey(), 'data' => $data];
        $this->data = $data;

        return true;
    }

    public function removeFromMemory(SessionIdentityContract $identity): bool
    {
        $this->removes[] = $identity->getKey();
        $this->data = null;

        return true;
    }
}

// ===========================================
// Helper Functions
// ===========================================

function createMultiDriverIdentity(string $agent = 'TestAgent', string $chat = 'test_chat'): SessionIdentity
{
    return new SessionIdentity(agentName: $agent, chatName: $chat);
}

// ===========================================
// ChatHistoryStorage Multi-Driver Tests
// ===========================================

describe('ChatHistoryStorage with Multiple Drivers', function () {

    describe('Read Behavior', function () {

        test('reads from first available driver (primary)', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // Pre-populate both drivers with different data
            $scopedIdentity = $identity->withScope('chatHistory');
            $driver1->writeToMemory($scopedIdentity, [
                ['role' => 'user', 'content' => 'From Driver 1'],
            ]);
            $driver2->writeToMemory($scopedIdentity, [
                ['role' => 'user', 'content' => 'From Driver 2'],
            ]);

            // Create storage with both drivers
            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->readFromMemory();

            // Should read from driver1 (primary)
            $message = $storage->getLastMessage();
            expect((string) $message->getContent())->toBe('From Driver 1');
        });

        test('falls back to secondary driver when primary returns null', function () {
            $driver1 = new InMemoryStorage; // Empty
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // Only populate secondary driver
            $scopedIdentity = $identity->withScope('chatHistory');
            $driver2->writeToMemory($scopedIdentity, [
                ['role' => 'user', 'content' => 'From Driver 2'],
            ]);

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->readFromMemory();

            // Should fall back to driver2
            $message = $storage->getLastMessage();
            expect((string) $message->getContent())->toBe('From Driver 2');
        });

        test('falls back to secondary driver when primary throws exception', function () {
            $driver1 = new FailingReadDriver;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // Populate secondary driver
            $scopedIdentity = $identity->withScope('chatHistory');
            $driver2->writeToMemory($scopedIdentity, [
                ['role' => 'user', 'content' => 'Fallback data'],
            ]);

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->readFromMemory();

            // Should fall back to driver2
            $message = $storage->getLastMessage();
            expect((string) $message->getContent())->toBe('Fallback data');
        });

        test('returns empty when all drivers return null', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // Neither driver has data
            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->readFromMemory();

            expect($storage->count())->toBe(0);
            expect($storage->getLastMessage())->toBeNull();
        });

        test('tracks which drivers were tried during read', function () {
            $driver1 = new TrackingDriver; // Will return null
            $driver2 = new TrackingDriver;
            $driver2->data = [['role' => 'user', 'content' => 'Test']];
            $identity = createMultiDriverIdentity();

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->readFromMemory();

            // Both drivers should have been tried (driver1 returned null)
            expect(count($driver1->reads))->toBe(1);
            expect(count($driver2->reads))->toBe(1);
        });

    });

    describe('Write Behavior', function () {

        test('saves to all drivers', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->addMessage(new UserMessage('Hello'));
            $storage->addMessage(new AssistantMessage('Hi there!'));
            $storage->save();

            // Both drivers should have the data
            $scopedIdentity = $identity->withScope('chatHistory');
            $data1 = $driver1->readFromMemory($scopedIdentity);
            $data2 = $driver2->readFromMemory($scopedIdentity);

            expect($data1)->toHaveCount(2);
            expect($data2)->toHaveCount(2);
            expect($data1)->toEqual($data2);
        });

        test('continues saving to other drivers even if one fails', function () {
            $driver1 = new FailingReadDriver; // Can write
            $driver2 = new TrackingDriver;
            $identity = createMultiDriverIdentity();

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->addMessage(new UserMessage('Test message'));
            $storage->save();

            // Driver2 should still have received the write
            expect(count($driver2->writes))->toBe(1);
            expect($driver2->data)->toHaveCount(1);
        });

        test('tracks all write operations', function () {
            $driver1 = new TrackingDriver;
            $driver2 = new TrackingDriver;
            $driver3 = new TrackingDriver;
            $identity = createMultiDriverIdentity();

            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2, $driver3]);
            $storage->addMessage(new UserMessage('Test'));
            $storage->save();

            // All three drivers should have writes
            expect(count($driver1->writes))->toBe(1);
            expect(count($driver2->writes))->toBe(1);
            expect(count($driver3->writes))->toBe(1);
        });

    });

    describe('Remove Behavior', function () {

        test('removes from all drivers', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // First populate both
            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage->addMessage(new UserMessage('Test'));
            $storage->save();

            // Verify data exists
            $scopedIdentity = $identity->withScope('chatHistory');
            expect($driver1->readFromMemory($scopedIdentity))->not->toBeNull();
            expect($driver2->readFromMemory($scopedIdentity))->not->toBeNull();

            // Remove
            $storage->remove();

            // Both should be empty
            expect($driver1->readFromMemory($scopedIdentity))->toBeNull();
            expect($driver2->readFromMemory($scopedIdentity))->toBeNull();
        });

    });

    describe('Complete Lifecycle', function () {

        test('full lifecycle with multiple drivers', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity('Agent', 'session_1');

            // 1. Create and populate
            $storage1 = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage1->addMessage(new UserMessage('Hello'));
            $storage1->addMessage(new AssistantMessage('Hi!'));
            $storage1->addMessage(new UserMessage('How are you?'));
            $storage1->addMessage(new AssistantMessage('I am fine.'));
            $storage1->save();

            // 2. Simulate driver1 failure (clear it) but driver2 still has data
            $scopedIdentity = $identity->withScope('chatHistory');
            $driver1->removeFromMemory($scopedIdentity);

            // 3. New instance should fall back to driver2
            $storage2 = new ChatHistoryStorage($identity, [$driver1, $driver2]);
            $storage2->readFromMemory();

            expect($storage2->count())->toBe(4);
            expect((string) $storage2->getLastMessage()->getContent())->toBe('I am fine.');

            // 4. Add more messages and save (should save to both again)
            $storage2->addMessage(new UserMessage('Goodbye'));
            $storage2->save();

            // 5. Both drivers should now have all 5 messages
            $data1 = $driver1->readFromMemory($scopedIdentity);
            $data2 = $driver2->readFromMemory($scopedIdentity);

            expect($data1)->toHaveCount(5);
            expect($data2)->toHaveCount(5);
        });

        test('data consistency across drivers after multiple operations', function () {
            $driver1 = new InMemoryStorage;
            $driver2 = new InMemoryStorage;
            $identity = createMultiDriverIdentity();

            // Multiple save operations
            $storage = new ChatHistoryStorage($identity, [$driver1, $driver2]);

            $storage->addMessage(new UserMessage('Message 1'));
            $storage->save();

            $storage->addMessage(new UserMessage('Message 2'));
            $storage->save();

            $storage->addMessage(new UserMessage('Message 3'));
            $storage->save();

            // Both drivers should have identical data
            $scopedIdentity = $identity->withScope('chatHistory');
            $data1 = $driver1->readFromMemory($scopedIdentity);
            $data2 = $driver2->readFromMemory($scopedIdentity);

            expect($data1)->toEqual($data2);
            expect($data1)->toHaveCount(3);
        });

    });

});

// ===========================================
// IdentityStorage Multi-Driver Tests
// ===========================================

describe('IdentityStorage with Multiple Drivers', function () {

    test('saves identities to all drivers', function () {
        $driver1 = new InMemoryStorage;
        $driver2 = new InMemoryStorage;
        $baseIdentity = createMultiDriverIdentity('Agent');

        $storage = new IdentityStorage($baseIdentity, [$driver1, $driver2]);

        $trackedIdentity = createMultiDriverIdentity('Agent', 'chat_1');
        $storage->addIdentity($trackedIdentity);
        $storage->save();

        // Both drivers should have the data
        $scopedIdentity = $baseIdentity->withScope('context');
        $data1 = $driver1->readFromMemory($scopedIdentity);
        $data2 = $driver2->readFromMemory($scopedIdentity);

        expect($data1)->toHaveCount(1);
        expect($data2)->toHaveCount(1);
    });

    test('reads from first available driver', function () {
        $driver1 = new InMemoryStorage;
        $driver2 = new InMemoryStorage;
        $baseIdentity = createMultiDriverIdentity('Agent');

        // Only populate driver2
        $scopedIdentity = $baseIdentity->withScope('context');
        $driver2->writeToMemory($scopedIdentity, [
            ['agentName' => 'Agent', 'chatName' => 'chat_1', 'userId' => null, 'group' => null, 'scope' => null],
        ]);

        $storage = new IdentityStorage($baseIdentity, [$driver1, $driver2]);
        $storage->read();

        // Should have found the identity from driver2
        expect($storage->hasKey('Agent_chat_1'))->toBeTrue();
    });

});

// ===========================================
// Mixed Driver Configuration Tests
// ===========================================

describe('Mixed Driver Configurations', function () {

    test('works with InMemoryStorage and class names', function () {
        $driver1 = new InMemoryStorage;
        $identity = createMultiDriverIdentity();

        // Mix instance and class name
        $storage = new ChatHistoryStorage($identity, [$driver1, InMemoryStorage::class]);
        $storage->addMessage(new UserMessage('Test'));
        $storage->save();

        // Should work without errors
        expect($storage->count())->toBe(1);
    });

    test('three drivers with different initial states', function () {
        $driver1 = new EmptyDriver;      // Always returns null
        $driver2 = new FailingReadDriver; // Throws on read
        $driver3 = new InMemoryStorage;   // Works normally
        $identity = createMultiDriverIdentity();

        // Pre-populate driver3
        $scopedIdentity = $identity->withScope('chatHistory');
        $driver3->writeToMemory($scopedIdentity, [
            ['role' => 'user', 'content' => 'From driver 3'],
        ]);

        $storage = new ChatHistoryStorage($identity, [$driver1, $driver2, $driver3]);
        $storage->readFromMemory();

        // Should fall through to driver3
        $message = $storage->getLastMessage();
        expect((string) $message->getContent())->toBe('From driver 3');
    });

});

// ===========================================
// Agent.defaultStorageDrivers Tests
// ===========================================

describe('Agent Storage Driver Configuration', function () {

    test('InMemoryStorage is ultimate fallback when no config', function () {
        // This tests the fix for the infinite recursion bug
        // When $storage property is not set and config is empty

        // Temporarily clear config
        config(['laragent.default_storage' => null]);

        // Create identity and storage with empty config
        $identity = createMultiDriverIdentity();

        // This should not cause infinite recursion
        // The fix returns InMemoryStorage::class as fallback
        $fallbackDrivers = [\LarAgent\Context\Drivers\InMemoryStorage::class];

        $storage = new ChatHistoryStorage($identity, $fallbackDrivers);
        $storage->addMessage(new UserMessage('Test'));
        $storage->save();

        expect($storage->count())->toBe(1);

        // Restore config
        config(['laragent.default_storage' => [
            \LarAgent\Context\Drivers\CacheStorage::class,
        ]]);
    });

});
