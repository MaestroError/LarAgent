<?php

use LarAgent\Context\Abstract\StorageDriver as AbstractStorageDriver;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\StorageManager;

// Mock SessionIdentity
class MockSessionIdentity implements SessionIdentityContract
{
    public function getAgentName(): string
    {
        return 'agent';
    }

    public function getChatName(): ?string
    {
        return 'chat';
    }

    public function getUserId(): ?string
    {
        return 'user';
    }

    public function getGroup(): ?string
    {
        return 'group';
    }

    public function getKey(): string
    {
        return 'key';
    }

    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        return new static;
    }

    public function withScope(string $scope): static
    {
        return new static;
    }
}

class FailingDriver extends AbstractStorageDriver
{
    public function readFromMemory(SessionIdentityContract $identity): ?array
    {
        throw new Exception('Read failed');
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): bool
    {
        return false;
    }

    public function removeFromMemory(SessionIdentityContract $identity): bool
    {
        return false;
    }
}

test('StorageManager resolves drivers from class names', function () {
    $manager = new StorageManager([InMemoryStorage::class]);
    expect($manager)->toBeInstanceOf(StorageManager::class);
});

test('StorageManager resolves drivers from instances', function () {
    $manager = new StorageManager([new InMemoryStorage]);
    expect($manager)->toBeInstanceOf(StorageManager::class);
});

test('StorageManager writes to all drivers', function () {
    $driver1 = new InMemoryStorage;
    $driver2 = new InMemoryStorage;
    $identity = new MockSessionIdentity;

    $manager = new StorageManager([$driver1, $driver2]);
    $manager->save($identity, ['key' => 'value']);

    expect($driver1->readFromMemory($identity))->toBe(['key' => 'value']);
    expect($driver2->readFromMemory($identity))->toBe(['key' => 'value']);
});

test('StorageManager reads from primary driver', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new InMemoryStorage;
    $driver1->writeToMemory($identity, ['key' => 'value1']);

    $driver2 = new class extends AbstractStorageDriver
    {
        public $data = null;

        public function readFromMemory(SessionIdentityContract $identity): ?array
        {
            return $this->data;
        }

        public function writeToMemory(SessionIdentityContract $identity, array $data): bool
        {
            $this->data = $data;

            return true;
        }

        public function removeFromMemory(SessionIdentityContract $identity): bool
        {
            $this->data = null;

            return true;
        }
    };
    $driver2->writeToMemory($identity, ['key' => 'value2']);

    $manager = new StorageManager([$driver1, $driver2]);

    expect($manager->read($identity))->toBe(['key' => 'value1']);
});

test('StorageManager falls back to secondary driver on null', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new InMemoryStorage;
    // driver1 returns null (no data for key)

    $driver2 = new class extends AbstractStorageDriver
    {
        public $data = null;

        public function readFromMemory(SessionIdentityContract $identity): ?array
        {
            return $this->data;
        }

        public function writeToMemory(SessionIdentityContract $identity, array $data): bool
        {
            $this->data = $data;

            return true;
        }

        public function removeFromMemory(SessionIdentityContract $identity): bool
        {
            $this->data = null;

            return true;
        }
    };
    $driver2->writeToMemory($identity, ['key' => 'value2']);

    $manager = new StorageManager([$driver1, $driver2]);

    expect($manager->read($identity))->toBe(['key' => 'value2']);
});

test('StorageManager falls back to secondary driver on exception', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new FailingDriver;

    $driver2 = new InMemoryStorage;
    $driver2->writeToMemory($identity, ['key' => 'value2']);

    $manager = new StorageManager([$driver1, $driver2]);

    expect($manager->read($identity))->toBe(['key' => 'value2']);
});

test('StorageManager throws exception if all return null', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new InMemoryStorage;
    $driver2 = new InMemoryStorage;

    $manager = new StorageManager([$driver1, $driver2]);

    expect(fn () => $manager->read($identity))->toThrow(Exception::class);
});

test('StorageManager continues writing even if one fails', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new FailingDriver;
    $driver2 = new InMemoryStorage;

    $manager = new StorageManager([$driver1, $driver2]);
    $manager->save($identity, ['key' => 'value']);

    expect($driver2->readFromMemory($identity))->toBe(['key' => 'value']);
});

test('StorageManager removes from all drivers', function () {
    $driver1 = new InMemoryStorage;
    $driver2 = new InMemoryStorage;
    $identity = new MockSessionIdentity;

    // First save some data
    $manager = new StorageManager([$driver1, $driver2]);
    $manager->save($identity, ['key' => 'value']);

    // Verify data exists
    expect($driver1->readFromMemory($identity))->toBe(['key' => 'value']);
    expect($driver2->readFromMemory($identity))->toBe(['key' => 'value']);

    // Remove
    $manager->remove($identity);

    // Verify data is gone from both
    expect($driver1->readFromMemory($identity))->toBeNull();
    expect($driver2->readFromMemory($identity))->toBeNull();
});

test('StorageManager continues removing even if one fails', function () {
    $identity = new MockSessionIdentity;
    $driver1 = new FailingDriver;
    $driver2 = new InMemoryStorage;

    // First save to driver2
    $driver2->writeToMemory($identity, ['key' => 'value']);

    $manager = new StorageManager([$driver1, $driver2]);
    $manager->remove($identity);

    // Driver2 should still have been removed even though driver1 failed
    expect($driver2->readFromMemory($identity))->toBeNull();
});
