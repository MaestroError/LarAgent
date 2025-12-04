<?php

declare(strict_types=1);

namespace Tests\LarAgent\Context;

use LarAgent\Context\Context;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Traits\HasContext;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

/**
 * Test class that uses the HasContext trait.
 */
class HasContextTestClass
{
    use HasContext;
    
    /**
     * Public method to access protected setChatSessionId.
     */
    public function publicSetChatSessionId(string $id, string $agentName): static
    {
        return $this->setChatSessionId($id, $agentName);
    }
    
    /**
     * Public method to access protected buildSessionId.
     */
    public function publicBuildSessionId(): string
    {
        return $this->buildSessionId();
    }
    
    /**
     * Public method to access protected buildIdentity.
     */
    public function publicBuildIdentity(): SessionIdentityContract
    {
        return $this->buildIdentity();
    }
    
    /**
     * Public method to access protected setupContext.
     */
    public function publicSetupContext(array $driversConfig = []): void
    {
        $this->setupContext($driversConfig);
    }
    
    /**
     * Set properties directly for testing.
     */
    public function setPropertiesForTest(
        string $agentName = 'TestAgent',
        string $chatKey = 'test_chat',
        ?string $userId = null,
        ?string $group = null
    ): static {
        $this->agentName = $agentName;
        $this->chatKey = $chatKey;
        $this->userId = $userId;
        $this->group = $group;
        return $this;
    }
}

describe('HasContext Trait', function () {
    
    describe('setChatSessionId', function () {
        
        test('sets chatKey from id', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('test_chat_id', 'TestAgent');
            
            expect($instance->getChatKey())->toBe('test_chat_id');
        });
        
        test('sets agentName', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('test_chat_id', 'MyTestAgent');
            
            expect($instance->getAgentName())->toBe('MyTestAgent');
        });
        
        test('does not set userId when usesUserId is false', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('test_id', 'TestAgent');
            
            expect($instance->getUserId())->toBeNull();
        });
        
        test('sets userId when usesUserId is true', function () {
            $instance = new HasContextTestClass();
            $instance->usesUserId();
            $instance->publicSetChatSessionId('user_123', 'TestAgent');
            
            expect($instance->getUserId())->toBe('user_123');
        });
        
        test('sets chatSessionId after building session', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('test_chat', 'TestAgent');
            
            expect($instance->getChatSessionId())->toBeString()
                ->and($instance->getChatSessionId())->not->toBeEmpty();
        });
        
        test('returns static for fluent interface', function () {
            $instance = new HasContextTestClass();
            $result = $instance->publicSetChatSessionId('test_chat', 'TestAgent');
            
            expect($result)->toBeInstanceOf(HasContextTestClass::class);
        });
        
    });
    
    describe('buildSessionId', function () {
        
        test('creates sessionIdentity', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            
            $sessionId = $instance->publicBuildSessionId();
            
            expect($sessionId)->toBeString()
                ->and($sessionId)->not->toBeEmpty();
        });
        
        test('returns key from sessionIdentity', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            
            $sessionId = $instance->publicBuildSessionId();
            
            // Build identity manually to compare
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat',
                userId: null,
                group: null
            );
            
            expect($sessionId)->toBe($identity->getKey());
        });
        
    });
    
    describe('buildIdentity', function () {
        
        test('creates SessionIdentity with correct agentName', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('MyAgent', 'chat123');
            
            $identity = $instance->publicBuildIdentity();
            
            expect($identity)->toBeInstanceOf(SessionIdentity::class)
                ->and($identity->getAgentName())->toBe('MyAgent');
        });
        
        test('creates SessionIdentity with correct chatName', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'my_chat');
            
            $identity = $instance->publicBuildIdentity();
            
            expect($identity->getChatName())->toBe('my_chat');
        });
        
        test('creates SessionIdentity with userId when set', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'chat', 'user_456');
            
            $identity = $instance->publicBuildIdentity();
            
            expect($identity->getUserId())->toBe('user_456');
        });
        
        test('creates SessionIdentity with group when set', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'chat', null, 'my_group');
            
            $identity = $instance->publicBuildIdentity();
            
            expect($identity->getGroup())->toBe('my_group');
        });
        
        test('creates SessionIdentity with all parameters', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('FullAgent', 'full_chat', 'full_user', 'full_group');
            
            $identity = $instance->publicBuildIdentity();
            
            expect($identity->getAgentName())->toBe('FullAgent')
                ->and($identity->getChatName())->toBe('full_chat')
                ->and($identity->getUserId())->toBe('full_user')
                ->and($identity->getGroup())->toBe('full_group');
        });
        
    });
    
    describe('setupContext', function () {
        
        test('creates Context instance', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            expect($instance->context())->toBeInstanceOf(Context::class);
        });
        
        test('passes drivers config to Context', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            $context = $instance->context();
            
            // Context should work with the in-memory driver
            expect($context)->toBeInstanceOf(Context::class);
        });
        
        test('uses existing sessionIdentity if already set', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            
            // First call buildSessionId to set sessionIdentity
            $sessionId = $instance->publicBuildSessionId();
            
            // Now setup context
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            $context = $instance->context();
            
            expect($context->getIdentity()->getKey())->toBe($sessionId);
        });
        
    });
    
    describe('usesUserId', function () {
        
        test('sets usesUserId flag to true', function () {
            $instance = new HasContextTestClass();
            
            expect($instance->hasUserId())->toBeFalse();
            
            $instance->usesUserId();
            
            expect($instance->hasUserId())->toBeTrue();
        });
        
        test('returns static for fluent interface', function () {
            $instance = new HasContextTestClass();
            $result = $instance->usesUserId();
            
            expect($result)->toBeInstanceOf(HasContextTestClass::class);
        });
        
    });
    
    describe('hasUserId', function () {
        
        test('returns false by default', function () {
            $instance = new HasContextTestClass();
            
            expect($instance->hasUserId())->toBeFalse();
        });
        
        test('returns true after usesUserId is called', function () {
            $instance = new HasContextTestClass();
            $instance->usesUserId();
            
            expect($instance->hasUserId())->toBeTrue();
        });
        
    });
    
    describe('getChatKey', function () {
        
        test('returns chatKey', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('my_chat_key', 'TestAgent');
            
            expect($instance->getChatKey())->toBe('my_chat_key');
        });
        
    });
    
    describe('getChatSessionId', function () {
        
        test('returns chatSessionId', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('chat_123', 'TestAgent');
            
            $sessionId = $instance->getChatSessionId();
            
            expect($sessionId)->toBeString()
                ->and($sessionId)->not->toBeEmpty();
        });
        
    });
    
    describe('getUserId', function () {
        
        test('returns null when not set', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('chat_id', 'TestAgent');
            
            expect($instance->getUserId())->toBeNull();
        });
        
        test('returns userId when usesUserId and set via setChatSessionId', function () {
            $instance = new HasContextTestClass();
            $instance->usesUserId();
            $instance->publicSetChatSessionId('user_123', 'TestAgent');
            
            expect($instance->getUserId())->toBe('user_123');
        });
        
        test('returns userId when set directly', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'chat', 'direct_user_id');
            
            expect($instance->getUserId())->toBe('direct_user_id');
        });
        
    });
    
    describe('getAgentName', function () {
        
        test('returns agentName', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('chat_id', 'MyAgentName');
            
            expect($instance->getAgentName())->toBe('MyAgentName');
        });
        
    });
    
    describe('group', function () {
        
        test('returns null by default', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'chat');
            
            expect($instance->group())->toBeNull();
        });
        
        test('returns group when set', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('Agent', 'chat', null, 'test_group');
            
            expect($instance->group())->toBe('test_group');
        });
        
    });
    
    describe('context', function () {
        
        test('returns Context instance', function () {
            $instance = new HasContextTestClass();
            $instance->setPropertiesForTest('TestAgent', 'test_chat');
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            expect($instance->context())->toBeInstanceOf(Context::class);
        });
        
    });
    
    describe('integration', function () {
        
        test('full workflow with userId', function () {
            $instance = new HasContextTestClass();
            
            // Enable userId tracking and set group via property
            $instance->usesUserId();
            $instance->setPropertiesForTest('WorkflowAgent', 'user_abc123', 'user_abc123', 'workflow_group');
            
            // Set session info
            $instance->publicSetChatSessionId('user_abc123', 'WorkflowAgent');
            
            // Setup context
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            // Verify all properties
            expect($instance->hasUserId())->toBeTrue()
                ->and($instance->getUserId())->toBe('user_abc123')
                ->and($instance->getChatKey())->toBe('user_abc123')
                ->and($instance->getAgentName())->toBe('WorkflowAgent')
                ->and($instance->group())->toBe('workflow_group')
                ->and($instance->context())->toBeInstanceOf(Context::class);
        });
        
        test('full workflow without userId', function () {
            $instance = new HasContextTestClass();
            
            // Set session info without enabling userId
            $instance->publicSetChatSessionId('session_key', 'SimpleAgent');
            
            // Setup context
            $instance->publicSetupContext([
                'history' => \LarAgent\Context\Drivers\InMemoryStorage::class,
                'identity' => \LarAgent\Context\Drivers\InMemoryStorage::class,
            ]);
            
            // Verify all properties
            expect($instance->hasUserId())->toBeFalse()
                ->and($instance->getUserId())->toBeNull()
                ->and($instance->getChatKey())->toBe('session_key')
                ->and($instance->getAgentName())->toBe('SimpleAgent')
                ->and($instance->group())->toBeNull()
                ->and($instance->context())->toBeInstanceOf(Context::class);
        });
        
        test('sessionIdentity matches expected key format', function () {
            $instance = new HasContextTestClass();
            $instance->publicSetChatSessionId('test_chat', 'TestAgent');
            
            // Build expected identity
            $expectedIdentity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'test_chat',
                userId: null,
                group: null
            );
            
            expect($instance->getChatSessionId())->toBe($expectedIdentity->getKey());
        });
        
        test('sessionIdentity with all parameters matches expected key', function () {
            $instance = new HasContextTestClass();
            
            // Enable userId and set group via property before building session
            $instance->usesUserId();
            $instance->setPropertiesForTest('FullAgent', 'user_123', null, 'test_group');
            
            // Set session
            $instance->publicSetChatSessionId('user_123', 'FullAgent');
            
            // Build expected identity
            $expectedIdentity = new SessionIdentity(
                agentName: 'FullAgent',
                chatName: 'user_123',
                userId: 'user_123',
                group: 'test_group'
            );
            
            expect($instance->getChatSessionId())->toBe($expectedIdentity->getKey());
        });
        
    });
    
});
