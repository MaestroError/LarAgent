<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;
use LarAgent\Context\Drivers\SessionStorage;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;

// Helper to create identity
function createDriverTestIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

// ===========================================
// InMemoryStorage Tests
// ===========================================

test('InMemoryStorage: reads null when no data exists', function () {
    $driver = new InMemoryStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('InMemoryStorage: writes and reads data correctly', function () {
    $driver = new InMemoryStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];
    $result = $driver->writeToMemory($identity, $data);

    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBe($data);
});

test('InMemoryStorage: removes data correctly', function () {
    $driver = new InMemoryStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $driver->writeToMemory($identity, ['test' => 'data']);
    expect($driver->readFromMemory($identity))->not->toBeNull();

    $result = $driver->removeFromMemory($identity);

    expect($result)->toBeTrue();
    expect($driver->readFromMemory($identity))->toBeNull();
});

test('InMemoryStorage: isolates data by identity', function () {
    $driver = new InMemoryStorage();
    $identity1 = createDriverTestIdentity('agent1', 'chat1');
    $identity2 = createDriverTestIdentity('agent2', 'chat2');

    $driver->writeToMemory($identity1, ['data' => 'one']);
    $driver->writeToMemory($identity2, ['data' => 'two']);

    expect($driver->readFromMemory($identity1))->toBe(['data' => 'one']);
    expect($driver->readFromMemory($identity2))->toBe(['data' => 'two']);
});

// ===========================================
// CacheStorage Tests
// ===========================================

test('CacheStorage: reads null when no data exists', function () {
    Cache::shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn(null);

    $driver = new CacheStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('CacheStorage: writes and reads data correctly', function () {
    $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];

    Cache::shouldReceive('put')
        ->once()
        ->with('agent_chat', $data);

    Cache::shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn($data);

    $driver = new CacheStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();

    expect($driver->readFromMemory($identity))->toBe($data);
});

test('CacheStorage: removes data correctly', function () {
    Cache::shouldReceive('forget')
        ->once()
        ->with('agent_chat');

    $driver = new CacheStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
});

test('CacheStorage: uses specified store', function () {
    $data = ['test' => 'data'];
    $mockStore = Mockery::mock();
    
    Cache::shouldReceive('store')
        ->with('redis')
        ->andReturn($mockStore);
    
    $mockStore->shouldReceive('put')
        ->once()
        ->with('agent_chat', $data);

    $driver = new CacheStorage('redis');
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();
});

test('CacheStorage: reads from specified store', function () {
    $data = ['test' => 'data'];
    $mockStore = Mockery::mock();
    
    Cache::shouldReceive('store')
        ->with('file')
        ->andReturn($mockStore);
    
    $mockStore->shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn($data);

    $driver = new CacheStorage('file');
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBe($data);
});

test('CacheStorage: removes from specified store', function () {
    $mockStore = Mockery::mock();
    
    Cache::shouldReceive('store')
        ->with('redis')
        ->andReturn($mockStore);
    
    $mockStore->shouldReceive('forget')
        ->once()
        ->with('agent_chat');

    $driver = new CacheStorage('redis');
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
});

// ===========================================
// SessionStorage Tests
// ===========================================

test('SessionStorage: reads null when no data exists', function () {
    Session::shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn(null);

    $driver = new SessionStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('SessionStorage: writes and reads data correctly', function () {
    $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];

    Session::shouldReceive('put')
        ->once()
        ->with('agent_chat', $data);

    Session::shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn($data);

    $driver = new SessionStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();

    expect($driver->readFromMemory($identity))->toBe($data);
});

test('SessionStorage: removes data correctly', function () {
    Session::shouldReceive('forget')
        ->once()
        ->with('agent_chat');

    $driver = new SessionStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
});

test('SessionStorage: returns null for non-array data', function () {
    Session::shouldReceive('get')
        ->once()
        ->with('agent_chat')
        ->andReturn('not an array');

    $driver = new SessionStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

// ===========================================
// FileStorage Tests
// ===========================================

test('FileStorage: reads null when file does not exist', function () {
    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();
    
    Storage::shouldReceive('exists')
        ->once()
        ->with('laragent_storage/agent_chat.json')
        ->andReturn(false);

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('FileStorage: writes and reads data correctly', function () {
    $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->with('laragent_storage')
        ->andReturn(true);

    Storage::shouldReceive('put')
        ->once()
        ->with('laragent_storage/agent_chat.json', $jsonData);

    Storage::shouldReceive('exists')
        ->with('laragent_storage/agent_chat.json')
        ->andReturn(true);

    Storage::shouldReceive('get')
        ->once()
        ->with('laragent_storage/agent_chat.json')
        ->andReturn($jsonData);

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();

    expect($driver->readFromMemory($identity))->toBe($data);
});

test('FileStorage: removes data correctly', function () {
    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->once()
        ->with('laragent_storage/agent_chat.json')
        ->andReturn(true);

    Storage::shouldReceive('delete')
        ->once()
        ->with('laragent_storage/agent_chat.json');

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
});

test('FileStorage: uses custom disk and folder', function () {
    $data = ['test' => 'data'];
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);

    Storage::shouldReceive('disk')
        ->with('s3')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->with('custom_folder')
        ->andReturn(true);

    Storage::shouldReceive('put')
        ->once()
        ->with('custom_folder/agent_chat.json', $jsonData);

    $driver = new FileStorage('s3', 'custom_folder');
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();
});

test('FileStorage: creates folder if not exists', function () {
    $data = ['test' => 'data'];
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->with('laragent_storage')
        ->andReturn(false);

    Storage::shouldReceive('makeDirectory')
        ->once()
        ->with('laragent_storage');

    Storage::shouldReceive('put')
        ->once()
        ->with('laragent_storage/agent_chat.json', $jsonData);

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();
});

test('FileStorage: sanitizes file names', function () {
    $data = ['test' => 'data'];
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->with('laragent_storage')
        ->andReturn(true);

    // Identity with special characters should be sanitized
    Storage::shouldReceive('put')
        ->once()
        ->with('laragent_storage/agent_with_special_chars_chat_123.json', $jsonData);

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent/with:special*chars', 'chat@123');

    $result = $driver->writeToMemory($identity, $data);
    expect($result)->toBeTrue();
});

test('FileStorage: handles invalid JSON gracefully', function () {
    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->with('laragent_storage/agent_chat.json')
        ->andReturn(true);

    Storage::shouldReceive('get')
        ->once()
        ->with('laragent_storage/agent_chat.json')
        ->andReturn('invalid json {');

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    expect($driver->readFromMemory($identity))->toBeNull();
});

test('FileStorage: remove returns true when file does not exist', function () {
    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();

    Storage::shouldReceive('exists')
        ->once()
        ->with('laragent_storage/agent_chat.json')
        ->andReturn(false);

    $driver = new FileStorage();
    $identity = createDriverTestIdentity('agent', 'chat');

    $result = $driver->removeFromMemory($identity);
    expect($result)->toBeTrue();
});

// ===========================================
// StorageDriver::make() Tests
// ===========================================

test('StorageDriver make creates instance without config', function () {
    $driver = InMemoryStorage::make();
    expect($driver)->toBeInstanceOf(InMemoryStorage::class);
});

test('CacheStorage make creates instance with store config', function () {
    $driver = CacheStorage::make(['redis']);
    expect($driver)->toBeInstanceOf(CacheStorage::class);
});

test('FileStorage make creates instance with disk and folder config', function () {
    $driver = FileStorage::make(['s3', 'my_folder']);
    expect($driver)->toBeInstanceOf(FileStorage::class);
});

test('SessionStorage make creates instance', function () {
    $driver = SessionStorage::make();
    expect($driver)->toBeInstanceOf(SessionStorage::class);
});
