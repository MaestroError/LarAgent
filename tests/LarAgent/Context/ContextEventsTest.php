<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use Illuminate\Support\Facades\Event;
use LarAgent\Context\Context;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Events\Context\ContextCreated;
use LarAgent\Events\Context\ContextSaving;
use LarAgent\Events\Context\ContextSaved;
use LarAgent\Events\Context\ContextReading;
use LarAgent\Events\Context\ContextRead;
use LarAgent\Events\Context\ContextClearing;
use LarAgent\Events\Context\ContextCleared;
use LarAgent\Events\Context\StorageRegistered;
use LarAgent\Events\IdentityStorage\IdentityAdding;
use LarAgent\Events\IdentityStorage\IdentityAdded;
use LarAgent\Events\IdentityStorage\IdentityStorageSaving;
use LarAgent\Events\IdentityStorage\IdentityStorageSaved;
use LarAgent\Events\IdentityStorage\IdentityStorageLoaded;

describe('Context Events', function () {
    
    describe('ContextCreated', function () {
        
        test('event contains context', function () {
            Event::fake([ContextCreated::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            
            Event::assertDispatched(ContextCreated::class, function (ContextCreated $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('event is dispatched on construction', function () {
            Event::fake([ContextCreated::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            new Context($identity, $driversConfig);
            
            Event::assertDispatched(ContextCreated::class);
        });
        
        test('event context property is readonly', function () {
            Event::fake([ContextCreated::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $context = new Context($identity, [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ]);
            
            Event::assertDispatched(ContextCreated::class, function (ContextCreated $event) {
                $reflection = new \ReflectionProperty($event, 'context');
                return $reflection->isReadOnly();
            });
        });
        
    });
    
    describe('StorageRegistered', function () {
        
        test('event contains context, prefix, and storage', function () {
            Event::fake([StorageRegistered::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $storage = new ChatHistoryStorage($identity, $driversConfig);
            
            $context->register($storage);
            
            // Verify the event was dispatched with correct properties
            // Note: ChatHistoryStorage::getStoragePrefix() returns 'chatHistory'
            Event::assertDispatched(StorageRegistered::class, function (StorageRegistered $event) use ($context, $storage) {
                return $event->context === $context
                    && $event->prefix === 'chatHistory'
                    && $event->storage === $storage;
            });
        });
        
        test('event is dispatched when storage is registered', function () {
            Event::fake([StorageRegistered::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $storage = new ChatHistoryStorage($identity, $driversConfig);
            
            $context->register($storage);
            
            Event::assertDispatched(StorageRegistered::class);
        });
        
        test('event properties are readonly', function () {
            Event::fake([StorageRegistered::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $storage = new ChatHistoryStorage($identity, $driversConfig);
            $context->register($storage);
            
            Event::assertDispatched(StorageRegistered::class, function (StorageRegistered $event) {
                $contextProp = new \ReflectionProperty($event, 'context');
                $prefixProp = new \ReflectionProperty($event, 'prefix');
                $storageProp = new \ReflectionProperty($event, 'storage');
                
                return $contextProp->isReadOnly() 
                    && $prefixProp->isReadOnly() 
                    && $storageProp->isReadOnly();
            });
        });
        
    });
    
    describe('ContextSaving and ContextSaved', function () {
        
        test('ContextSaving is dispatched before save', function () {
            Event::fake([ContextSaving::class, ContextSaved::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->save();
            
            Event::assertDispatched(ContextSaving::class, function (ContextSaving $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('ContextSaved is dispatched after save', function () {
            Event::fake([ContextSaving::class, ContextSaved::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->save();
            
            Event::assertDispatched(ContextSaved::class, function (ContextSaved $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('both saving events are dispatched', function () {
            Event::fake([ContextSaving::class, ContextSaved::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->save();
            
            Event::assertDispatched(ContextSaving::class);
            Event::assertDispatched(ContextSaved::class);
        });
        
    });
    
    describe('ContextReading and ContextRead', function () {
        
        test('ContextReading is dispatched before read', function () {
            Event::fake([ContextReading::class, ContextRead::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->read();
            
            Event::assertDispatched(ContextReading::class, function (ContextReading $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('ContextRead is dispatched after read', function () {
            Event::fake([ContextReading::class, ContextRead::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->read();
            
            Event::assertDispatched(ContextRead::class, function (ContextRead $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('both reading events are dispatched', function () {
            Event::fake([ContextReading::class, ContextRead::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->read();
            
            Event::assertDispatched(ContextReading::class);
            Event::assertDispatched(ContextRead::class);
        });
        
    });
    
    describe('ContextClearing and ContextCleared', function () {
        
        test('ContextClearing is dispatched before clear', function () {
            Event::fake([ContextClearing::class, ContextCleared::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->clear();
            
            Event::assertDispatched(ContextClearing::class, function (ContextClearing $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('ContextCleared is dispatched after clear', function () {
            Event::fake([ContextClearing::class, ContextCleared::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->clear();
            
            Event::assertDispatched(ContextCleared::class, function (ContextCleared $event) use ($context) {
                return $event->context === $context;
            });
        });
        
        test('both clearing events are dispatched', function () {
            Event::fake([ContextClearing::class, ContextCleared::class]);
            
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $context = new Context($identity, $driversConfig);
            $context->clear();
            
            Event::assertDispatched(ContextClearing::class);
            Event::assertDispatched(ContextCleared::class);
        });
        
    });
    
});

describe('IdentityStorage Events', function () {
    
    describe('IdentityAdding and IdentityAdded', function () {
        
        test('IdentityAdding event contains storage and identity', function () {
            Event::fake([IdentityAdding::class, IdentityAdded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            Event::assertDispatched(IdentityAdding::class, function (IdentityAdding $event) use ($identityStorage, $newIdentity) {
                return $event->storage === $identityStorage
                    && $event->identity === $newIdentity;
            });
        });
        
        test('IdentityAdded event contains storage and identity', function () {
            Event::fake([IdentityAdding::class, IdentityAdded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            Event::assertDispatched(IdentityAdded::class, function (IdentityAdded $event) use ($identityStorage, $newIdentity) {
                return $event->storage === $identityStorage
                    && $event->identity === $newIdentity;
            });
        });
        
        test('both adding events are dispatched when identity is added', function () {
            Event::fake([IdentityAdding::class, IdentityAdded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            Event::assertDispatched(IdentityAdding::class);
            Event::assertDispatched(IdentityAdded::class);
        });
        
        test('IdentityAdding dispatched but IdentityAdded not dispatched for duplicate identity', function () {
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            
            // Add first time (no fake - just to add)
            $identityStorage->addIdentity($newIdentity);
            
            // Now fake events and try to add duplicate
            Event::fake([IdentityAdding::class, IdentityAdded::class]);
            
            // Add duplicate - IdentityAdding should fire (always), but IdentityAdded should NOT
            $identityStorage->addIdentity($newIdentity);
            
            Event::assertDispatched(IdentityAdding::class);
            Event::assertNotDispatched(IdentityAdded::class);
        });
        
        test('event properties are readonly', function () {
            Event::fake([IdentityAdding::class, IdentityAdded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            Event::assertDispatched(IdentityAdding::class, function (IdentityAdding $event) {
                $storageProp = new \ReflectionProperty($event, 'storage');
                $identityProp = new \ReflectionProperty($event, 'identity');
                
                return $storageProp->isReadOnly() && $identityProp->isReadOnly();
            });
            
            Event::assertDispatched(IdentityAdded::class, function (IdentityAdded $event) {
                $storageProp = new \ReflectionProperty($event, 'storage');
                $identityProp = new \ReflectionProperty($event, 'identity');
                
                return $storageProp->isReadOnly() && $identityProp->isReadOnly();
            });
        });
        
    });
    
    describe('IdentityStorageSaving and IdentityStorageSaved', function () {
        
        test('IdentityStorageSaving event contains storage and identities', function () {
            Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            // Add an identity to make it dirty
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            $identityStorage->save();
            
            Event::assertDispatched(IdentityStorageSaving::class, function (IdentityStorageSaving $event) use ($identityStorage) {
                return $event->storage === $identityStorage
                    && $event->identities instanceof SessionIdentityArray;
            });
        });
        
        test('IdentityStorageSaved event contains storage', function () {
            Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            // Add an identity to make it dirty
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            $identityStorage->save();
            
            Event::assertDispatched(IdentityStorageSaved::class, function (IdentityStorageSaved $event) use ($identityStorage) {
                return $event->storage === $identityStorage;
            });
        });
        
        test('both saving events are dispatched when storage is saved', function () {
            Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            // Add an identity to make it dirty
            $newIdentity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            $identityStorage->addIdentity($newIdentity);
            
            $identityStorage->save();
            
            Event::assertDispatched(IdentityStorageSaving::class);
            Event::assertDispatched(IdentityStorageSaved::class);
        });
        
        test('saving events contain correct identities', function () {
            Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            // Add multiple identities
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            $identityStorage->addIdentity($identity1);
            $identityStorage->addIdentity($identity2);
            
            $identityStorage->save();
            
            Event::assertDispatched(IdentityStorageSaving::class, function (IdentityStorageSaving $event) use ($identity1, $identity2) {
                $keys = $event->identities->getKeys();
                return in_array($identity1->getKey(), $keys) 
                    && in_array($identity2->getKey(), $keys);
            });
        });
        
        test('saving events are not dispatched when storage is not dirty', function () {
            Event::fake([IdentityStorageSaving::class, IdentityStorageSaved::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            
            // Save without adding anything (not dirty)
            $identityStorage->save();
            
            Event::assertNotDispatched(IdentityStorageSaving::class);
            Event::assertNotDispatched(IdentityStorageSaved::class);
        });
        
    });
    
    describe('IdentityStorageLoaded', function () {
        
        test('IdentityStorageLoaded event contains storage and items', function () {
            Event::fake([IdentityStorageLoaded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            $identityStorage->read();
            
            Event::assertDispatched(IdentityStorageLoaded::class, function (IdentityStorageLoaded $event) use ($identityStorage) {
                return $event->storage === $identityStorage
                    && $event->identities instanceof SessionIdentityArray;
            });
        });
        
        test('event is dispatched when read is called', function () {
            Event::fake([IdentityStorageLoaded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            $identityStorage->read();
            
            Event::assertDispatched(IdentityStorageLoaded::class);
        });
        
        test('event properties are readonly', function () {
            Event::fake([IdentityStorageLoaded::class]);
            
            $contextIdentity = new SessionIdentity(agentName: 'context', chatName: 'identities');
            $driversConfig = [
                'history' => InMemoryStorage::class,
                'identity' => InMemoryStorage::class,
            ];
            
            $identityStorage = new IdentityStorage($contextIdentity, $driversConfig);
            $identityStorage->read();
            
            Event::assertDispatched(IdentityStorageLoaded::class, function (IdentityStorageLoaded $event) {
                $storageProp = new \ReflectionProperty($event, 'storage');
                $identitiesProp = new \ReflectionProperty($event, 'identities');
                
                return $storageProp->isReadOnly() && $identitiesProp->isReadOnly();
            });
        });
        
    });
    
});

describe('Event Classes Structure', function () {
    
    describe('Context Event Classes', function () {
        
        test('ContextCreated uses Dispatchable trait', function () {
            $traits = class_uses(ContextCreated::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextSaving uses Dispatchable trait', function () {
            $traits = class_uses(ContextSaving::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextSaved uses Dispatchable trait', function () {
            $traits = class_uses(ContextSaved::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextReading uses Dispatchable trait', function () {
            $traits = class_uses(ContextReading::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextRead uses Dispatchable trait', function () {
            $traits = class_uses(ContextRead::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextClearing uses Dispatchable trait', function () {
            $traits = class_uses(ContextClearing::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('ContextCleared uses Dispatchable trait', function () {
            $traits = class_uses(ContextCleared::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('StorageRegistered uses Dispatchable trait', function () {
            $traits = class_uses(StorageRegistered::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
    });
    
    describe('IdentityStorage Event Classes', function () {
        
        test('IdentityAdding uses Dispatchable trait', function () {
            $traits = class_uses(IdentityAdding::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('IdentityAdded uses Dispatchable trait', function () {
            $traits = class_uses(IdentityAdded::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('IdentityStorageSaving uses Dispatchable trait', function () {
            $traits = class_uses(IdentityStorageSaving::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('IdentityStorageSaved uses Dispatchable trait', function () {
            $traits = class_uses(IdentityStorageSaved::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
        test('IdentityStorageLoaded uses Dispatchable trait', function () {
            $traits = class_uses(IdentityStorageLoaded::class);
            expect($traits)->toContain(\Illuminate\Foundation\Events\Dispatchable::class);
        });
        
    });
    
});
