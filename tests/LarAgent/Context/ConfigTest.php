<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use Illuminate\Support\Facades\Config;
use LarAgent\Context\Context;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;

// ===========================================
// Helper Functions
// ===========================================

function createConfigTestIdentity(string $agentName = 'ConfigTestAgent', string $chatName = 'test_chat'): SessionIdentity
{
    return new SessionIdentity(
        agentName: $agentName,
        chatName: $chatName
    );
}

// ===========================================
// Config-based Driver Resolution Tests
// ===========================================

describe('Config-based Storage Drivers', function () {
    
    test('Context uses provided drivers config over defaults', function () {
        $customDriver = new InMemoryStorage();
        $identity = createConfigTestIdentity();
        
        // Provide explicit driver config
        $context = new Context($identity, [$customDriver]);
        
        // The context should use the provided driver
        $driversConfig = $context->getDriversConfig();
        
        expect($driversConfig)->toContain($customDriver);
    });
    
    test('Context accepts array of driver class names', function () {
        $identity = createConfigTestIdentity();
        
        // Provide class names instead of instances
        $driversConfig = [
            InMemoryStorage::class,
        ];
        
        $context = new Context($identity, $driversConfig);
        
        expect($context->getDriversConfig())->toBe($driversConfig);
    });
    
    test('ChatHistoryStorage uses drivers config from Context make()', function () {
        $driver = new InMemoryStorage();
        $identity = createConfigTestIdentity();
        
        $context = new Context($identity, [$driver]);
        
        // Make storage - should inherit drivers from context
        $storage = $context->make(ChatHistoryStorage::class);
        
        expect($storage)->toBeInstanceOf(ChatHistoryStorage::class);
        
        // Storage should work with the inherited driver
        $storage->addMessage(new \LarAgent\Messages\UserMessage('Test'));
        $context->save();
        
        // Verify data was saved via the driver
        $newContext = new Context(createConfigTestIdentity(), [$driver]);
        $newStorage = $newContext->make(ChatHistoryStorage::class);
        $newStorage->read();
        
        expect($newStorage->getMessages()->count())->toBe(1);
    });
    
    test('Context make() uses custom drivers config when provided', function () {
        $defaultDriver = new InMemoryStorage();
        $customDriver = new InMemoryStorage(); // Separate instance
        
        $identity = createConfigTestIdentity('CustomDriverAgent');
        
        // Context with default driver
        $context = new Context($identity, [$defaultDriver]);
        
        // Make storage with custom driver override
        $storage = $context->make(ChatHistoryStorage::class, [$customDriver]);
        
        expect($storage)->toBeInstanceOf(ChatHistoryStorage::class);
        
        // Add data and save
        $storage->addMessage(new \LarAgent\Messages\UserMessage('Custom driver data'));
        $context->save();
        
        // Verify data is in custom driver, not default
        $verifyIdentity = createConfigTestIdentity('CustomDriverAgent');
        $verifyContext = new Context($verifyIdentity, [$customDriver]);
        $verifyStorage = $verifyContext->make(ChatHistoryStorage::class, [$customDriver]);
        $verifyStorage->read();
        
        expect($verifyStorage->getMessages()->count())->toBe(1);
    });
    
});

// ===========================================
// Default Config Value Tests
// ===========================================

describe('Default Config Values', function () {
    
    test('config has default_storage key defined', function () {
        // Check the config file defines default_storage
        $config = require __DIR__.'/../../../config/laragent.php';
        
        expect($config)->toHaveKey('default_storage');
        expect($config['default_storage'])->toBeArray();
    });
    
    test('config has default_history_storage key defined', function () {
        $config = require __DIR__.'/../../../config/laragent.php';
        
        expect($config)->toHaveKey('default_history_storage');
        expect($config['default_history_storage'])->toBeArray();
    });
    
    test('default_storage contains valid driver classes', function () {
        $config = require __DIR__.'/../../../config/laragent.php';
        
        foreach ($config['default_storage'] as $driverClass) {
            expect(class_exists($driverClass))->toBeTrue(
                "Driver class {$driverClass} does not exist"
            );
        }
    });
    
    test('default_history_storage contains valid driver classes', function () {
        $config = require __DIR__.'/../../../config/laragent.php';
        
        foreach ($config['default_history_storage'] as $driverClass) {
            expect(class_exists($driverClass))->toBeTrue(
                "Driver class {$driverClass} does not exist"
            );
        }
    });
    
    test('CacheStorage is primary driver in default_storage config', function () {
        $config = require __DIR__.'/../../../config/laragent.php';
        
        // First driver is primary
        expect($config['default_storage'][0])->toBe(CacheStorage::class);
    });
    
    test('CacheStorage and FileStorage are configured for history', function () {
        $config = require __DIR__.'/../../../config/laragent.php';
        
        expect($config['default_history_storage'])->toContain(CacheStorage::class);
        expect($config['default_history_storage'])->toContain(FileStorage::class);
    });
    
});

// ===========================================
// Driver Class Resolution Tests
// ===========================================

describe('Driver Class Resolution', function () {
    
    test('InMemoryStorage can be instantiated without config', function () {
        $driver = InMemoryStorage::make([]);
        
        expect($driver)->toBeInstanceOf(InMemoryStorage::class);
    });
    
    test('CacheStorage can be instantiated with store config', function () {
        $driver = CacheStorage::make(['store' => 'array']);
        
        expect($driver)->toBeInstanceOf(CacheStorage::class);
    });
    
    test('FileStorage can be instantiated with disk and folder config', function () {
        $driver = FileStorage::make([
            'disk' => 'local',
            'folder' => 'test_storage'
        ]);
        
        expect($driver)->toBeInstanceOf(FileStorage::class);
    });
    
    test('Context resolves driver class names to instances', function () {
        $identity = createConfigTestIdentity();
        
        // Pass class name in array - Context should handle resolution
        $context = new Context($identity, [InMemoryStorage::class]);
        
        // Context should have stored the config
        expect($context->getDriversConfig())->toBe([InMemoryStorage::class]);
        
        // Storage creation should work
        $storage = $context->make(ChatHistoryStorage::class);
        expect($storage)->toBeInstanceOf(ChatHistoryStorage::class);
    });
    
});

// ===========================================
// Config Fallback Tests
// ===========================================

describe('Config Fallback Behavior', function () {
    
    test('empty drivers config throws exception', function () {
        $identity = createConfigTestIdentity();
        
        // Empty array should throw because IdentityStorage needs at least one driver
        expect(fn() => new Context($identity, []))->toThrow(\InvalidArgumentException::class);
    });
    
    test('Context accepts mixed driver configs (instances and class names)', function () {
        $instanceDriver = new InMemoryStorage();
        $identity = createConfigTestIdentity();
        
        // Mix of instance and class name
        $context = new Context($identity, [
            $instanceDriver,
            InMemoryStorage::class, // Class name as fallback
        ]);
        
        expect($context->getDriversConfig())->toHaveCount(2);
    });
    
});
