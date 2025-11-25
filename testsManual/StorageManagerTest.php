<?php

use LarAgent\Context\StorageManager;
use LarAgent\Context\Contracts\StorageDriver;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

// Mock SessionIdentity
class MockSessionIdentity implements SessionIdentityContract
{
    public function getAgentName(): string { return 'agent'; }
    public function getChatName(): ?string { return 'chat'; }
    public function getUserId(): ?string { return 'user'; }
    public function getGroup(): ?string { return 'group'; }
    public function getKey(): string { return 'key'; }
    public function toArray(): array { return []; }
    public static function fromArray(array $data): static { return new static(); }
}

class InMemoryDriver implements StorageDriver
{
    protected array $data = [];

    public function readFromMemory(SessionIdentityContract $identity): array
    {
        return $this->data[$identity->getKey()] ?? [];
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): void
    {
        $this->data[$identity->getKey()] = $data;
    }
}

class FailingDriver implements StorageDriver
{
    public function readFromMemory(SessionIdentityContract $identity): array
    {
        throw new Exception("Read failed");
    }

    public function writeToMemory(SessionIdentityContract $identity, array $data): void
    {
        throw new Exception("Write failed");
    }
}

test('StorageManager resolves drivers from class names', function () {
    $manager = new StorageManager([InMemoryDriver::class]);
    expect($manager)->toBeInstanceOf(StorageManager::class);
});

test('StorageManager resolves drivers from instances', function () {
    $manager = new StorageManager([new InMemoryDriver()]);
    expect($manager)->toBeInstanceOf(StorageManager::class);
});

test('StorageManager writes to all drivers', function () {
    $driver1 = new InMemoryDriver();
    $driver2 = new InMemoryDriver();
    $identity = new MockSessionIdentity();
    
    $manager = new StorageManager([$driver1, $driver2]);
    $manager->save($identity, ['key' => 'value']);
    
    expect($driver1->readFromMemory($identity))->toBe(['key' => 'value']);
    expect($driver2->readFromMemory($identity))->toBe(['key' => 'value']);
});

test('StorageManager reads from primary driver', function () {
    $identity = new MockSessionIdentity();
    $driver1 = new InMemoryDriver();
    $driver1->writeToMemory($identity, ['key' => 'value1']);
    
    $driver2 = new InMemoryDriver();
    $driver2->writeToMemory($identity, ['key' => 'value2']);
    
    $manager = new StorageManager([$driver1, $driver2]);
    
    expect($manager->read($identity))->toBe(['key' => 'value1']);
});

test('StorageManager falls back to secondary driver on empty', function () {
    $identity = new MockSessionIdentity();
    $driver1 = new InMemoryDriver();
    // driver1 is empty
    
    $driver2 = new InMemoryDriver();
    $driver2->writeToMemory($identity, ['key' => 'value2']);
    
    $manager = new StorageManager([$driver1, $driver2]);
    
    expect($manager->read($identity))->toBe(['key' => 'value2']);
});

test('StorageManager falls back to secondary driver on exception', function () {
    $identity = new MockSessionIdentity();
    $driver1 = new FailingDriver();
    
    $driver2 = new InMemoryDriver();
    $driver2->writeToMemory($identity, ['key' => 'value2']);
    
    $manager = new StorageManager([$driver1, $driver2]);
    
    expect($manager->read($identity))->toBe(['key' => 'value2']);
});

test('StorageManager throws exception if all fail', function () {
    $identity = new MockSessionIdentity();
    $driver1 = new InMemoryDriver();
    $driver2 = new InMemoryDriver();
    
    $manager = new StorageManager([$driver1, $driver2]);
    
    expect(fn() => $manager->read($identity))->toThrow(Exception::class);
});

test('StorageManager continues writing even if one fails', function () {
    $identity = new MockSessionIdentity();
    $driver1 = new FailingDriver();
    $driver2 = new InMemoryDriver();
    
    $manager = new StorageManager([$driver1, $driver2]);
    $manager->save($identity, ['key' => 'value']);
    
    expect($driver2->readFromMemory($identity))->toBe(['key' => 'value']);
});
