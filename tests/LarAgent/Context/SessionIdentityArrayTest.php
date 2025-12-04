<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use LarAgent\Context\DataModels\SessionIdentityArray;
use LarAgent\Context\SessionIdentity;

describe('SessionIdentityArray', function () {
    
    describe('allowedModels', function () {
        
        test('allows only SessionIdentity models', function () {
            $allowed = SessionIdentityArray::allowedModels();
            
            expect($allowed)->toBe([SessionIdentity::class]);
        });
        
        test('rejects non-SessionIdentity models', function () {
            $array = new SessionIdentityArray();
            
            // Using a mock stdClass object should fail type validation
            // The DataModelArray validates types on add/fill (PHP type hint)
            $invalidObject = new \stdClass();
            
            $array->add($invalidObject);
        })->throws(\TypeError::class);
        
    });
    
    describe('hasKey', function () {
        
        test('returns true when identity with key exists', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat',
                userId: 'user123'
            );
            
            $array->add($identity);
            
            expect($array->hasKey($identity->getKey()))->toBeTrue();
        });
        
        test('returns false when identity with key does not exist', function () {
            $array = new SessionIdentityArray();
            
            expect($array->hasKey('nonexistent_key'))->toBeFalse();
        });
        
        test('returns false for empty array', function () {
            $array = new SessionIdentityArray();
            
            expect($array->hasKey('any_key'))->toBeFalse();
        });
        
    });
    
    describe('getByKey', function () {
        
        test('returns identity when found', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat',
                userId: 'user123'
            );
            
            $array->add($identity);
            
            $found = $array->getByKey($identity->getKey());
            
            expect($found)->toBeInstanceOf(SessionIdentity::class)
                ->and($found->getAgentName())->toBe('TestAgent')
                ->and($found->getChatName())->toBe('test_chat')
                ->and($found->getUserId())->toBe('user123');
        });
        
        test('returns null when not found', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat'
            );
            
            $array->add($identity);
            
            expect($array->getByKey('nonexistent_key'))->toBeNull();
        });
        
        test('returns correct identity when multiple exist', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            $identity3 = new SessionIdentity(agentName: 'Agent3', chatName: 'chat3');
            
            $array->add($identity1);
            $array->add($identity2);
            $array->add($identity3);
            
            $found = $array->getByKey($identity2->getKey());
            
            expect($found)->toBeInstanceOf(SessionIdentity::class)
                ->and($found->getAgentName())->toBe('Agent2')
                ->and($found->getChatName())->toBe('chat2');
        });
        
    });
    
    describe('removeByKey', function () {
        
        test('removes identity by key', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat'
            );
            
            $array->add($identity);
            $key = $identity->getKey();
            
            expect($array->hasKey($key))->toBeTrue();
            
            $array->removeByKey($key);
            
            expect($array->hasKey($key))->toBeFalse();
        });
        
        test('returns static for fluent interface', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat'
            );
            
            $array->add($identity);
            
            $result = $array->removeByKey($identity->getKey());
            
            expect($result)->toBeInstanceOf(SessionIdentityArray::class);
        });
        
        test('handles removing non-existent key gracefully', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat'
            );
            
            $array->add($identity);
            
            // Remove non-existent key should not throw
            $array->removeByKey('nonexistent_key');
            
            // Original should still be there
            expect($array->hasKey($identity->getKey()))->toBeTrue();
        });
        
        test('removes only matching identity when multiple exist', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            $identity3 = new SessionIdentity(agentName: 'Agent3', chatName: 'chat3');
            
            $array->add($identity1);
            $array->add($identity2);
            $array->add($identity3);
            
            $array->removeByKey($identity2->getKey());
            
            expect($array->hasKey($identity1->getKey()))->toBeTrue()
                ->and($array->hasKey($identity2->getKey()))->toBeFalse()
                ->and($array->hasKey($identity3->getKey()))->toBeTrue();
        });
        
    });
    
    describe('getKeys', function () {
        
        test('returns empty array when no identities', function () {
            $array = new SessionIdentityArray();
            
            expect($array->getKeys())->toBe([]);
        });
        
        test('returns array of all keys', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            $keys = $array->getKeys();
            
            expect($keys)->toBeArray()
                ->and($keys)->toHaveCount(2)
                ->and($keys)->toContain($identity1->getKey())
                ->and($keys)->toContain($identity2->getKey());
        });
        
        test('returns string array', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            
            $array->add($identity);
            
            $keys = $array->getKeys();
            
            expect($keys)->toBeArray()
                ->and($keys[0])->toBeString();
        });
        
    });
    
    describe('fromArray and toArray', function () {
        
        test('toArray serializes identities correctly', function () {
            $array = new SessionIdentityArray();
            
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat',
                userId: 'user123',
                group: 'test_group'
            );
            
            $array->add($identity);
            
            $serialized = $array->toArray();
            
            expect($serialized)->toBeArray()
                ->and($serialized)->toHaveCount(1)
                ->and($serialized[0])->toBeArray()
                ->and($serialized[0]['agentName'])->toBe('TestAgent')
                ->and($serialized[0]['chatName'])->toBe('test_chat')
                ->and($serialized[0]['userId'])->toBe('user123')
                ->and($serialized[0]['group'])->toBe('test_group');
        });
        
        test('fromArray reconstructs identities correctly', function () {
            $data = [
                [
                    'agentName' => 'TestAgent',
                    'chatName' => 'test_chat',
                    'userId' => 'user123',
                    'group' => 'test_group',
                ],
            ];
            
            $array = SessionIdentityArray::fromArray($data);
            
            expect($array)->toBeInstanceOf(SessionIdentityArray::class)
                ->and($array->all())->toHaveCount(1);
            
            $identity = $array->first();
            
            expect($identity)->toBeInstanceOf(SessionIdentity::class)
                ->and($identity->getAgentName())->toBe('TestAgent')
                ->and($identity->getChatName())->toBe('test_chat')
                ->and($identity->getUserId())->toBe('user123')
                ->and($identity->getGroup())->toBe('test_group');
        });
        
        test('fromArray and toArray are reversible', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1', userId: 'user1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2', group: 'group2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            $serialized = $array->toArray();
            $restored = SessionIdentityArray::fromArray($serialized);
            
            expect($restored->all())->toHaveCount(2);
            
            // Check keys match
            expect($restored->getKeys())->toBe($array->getKeys());
        });
        
        test('fromArray handles empty array', function () {
            $array = SessionIdentityArray::fromArray([]);
            
            expect($array)->toBeInstanceOf(SessionIdentityArray::class)
                ->and($array->isEmpty())->toBeTrue();
        });
        
    });
    
    describe('inherited DataModelArray methods', function () {
        
        test('add adds identity to array', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            
            $array->add($identity);
            
            expect($array->all())->toHaveCount(1)
                ->and($array->first())->toBe($identity);
        });
        
        test('remove removes identity from array', function () {
            $array = new SessionIdentityArray();
            $identity = new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat');
            
            $array->add($identity);
            $array->remove($identity);
            
            expect($array->isEmpty())->toBeTrue();
        });
        
        test('isEmpty returns correct status', function () {
            $array = new SessionIdentityArray();
            
            expect($array->isEmpty())->toBeTrue();
            
            $array->add(new SessionIdentity(agentName: 'TestAgent', chatName: 'test_chat'));
            
            expect($array->isEmpty())->toBeFalse();
        });
        
        test('first returns first identity', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            expect($array->first())->toBe($identity1);
        });
        
        test('last returns last identity', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            expect($array->last())->toBe($identity2);
        });
        
        test('all returns all identities', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            $all = $array->all();
            
            expect($all)->toHaveCount(2)
                ->and($all[0])->toBe($identity1)
                ->and($all[1])->toBe($identity2);
        });
        
        test('clear removes all identities', function () {
            $array = new SessionIdentityArray();
            
            $array->add(new SessionIdentity(agentName: 'Agent1', chatName: 'chat1'));
            $array->add(new SessionIdentity(agentName: 'Agent2', chatName: 'chat2'));
            
            expect($array->all())->toHaveCount(2);
            
            $array->clear();
            
            expect($array->isEmpty())->toBeTrue();
        });
        
        test('filter returns matching identities', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1', userId: 'user123');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2', userId: 'user456');
            $identity3 = new SessionIdentity(agentName: 'Agent3', chatName: 'chat3', userId: 'user123');
            
            $array->add($identity1);
            $array->add($identity2);
            $array->add($identity3);
            
            $filtered = $array->filter(fn(SessionIdentity $item) => $item->getUserId() === 'user123');
            
            expect($filtered)->toBeInstanceOf(SessionIdentityArray::class)
                ->and($filtered->all())->toHaveCount(2);
        });
        
        test('map transforms identities', function () {
            $array = new SessionIdentityArray();
            
            $identity1 = new SessionIdentity(agentName: 'Agent1', chatName: 'chat1');
            $identity2 = new SessionIdentity(agentName: 'Agent2', chatName: 'chat2');
            
            $array->add($identity1);
            $array->add($identity2);
            
            $agentNames = $array->map(fn(SessionIdentity $item) => $item->getAgentName());
            
            expect($agentNames)->toBe(['Agent1', 'Agent2']);
        });
        
    });
    
});
