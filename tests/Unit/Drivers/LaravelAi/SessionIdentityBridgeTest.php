<?php

use LarAgent\Context\SessionIdentity;
use LarAgent\Drivers\LaravelAi\SessionIdentityBridge;

describe('SessionIdentityBridge', function () {

    describe('toSdkUserId', function () {
        it('extracts userId from identity', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
            );

            expect(SessionIdentityBridge::toSdkUserId($identity))->toBe('user-456');
        });

        it('returns null when identity has no userId', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
            );

            expect(SessionIdentityBridge::toSdkUserId($identity))->toBeNull();
        });
    });

    describe('toSdkConversationId', function () {
        it('returns the identity composite key', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
            );

            $conversationId = SessionIdentityBridge::toSdkConversationId($identity);

            // Key format: agentName_userId (when userId is set)
            expect($conversationId)->toBe($identity->getKey());
            expect($conversationId)->toContain('TestAgent');
        });

        it('uses chatName when no userId', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'my-chat',
            );

            $conversationId = SessionIdentityBridge::toSdkConversationId($identity);

            expect($conversationId)->toBe($identity->getKey());
            expect($conversationId)->toContain('my-chat');
        });
    });

    describe('fromSdkConversation', function () {
        it('builds SessionIdentity from SDK params', function () {
            $identity = SessionIdentityBridge::fromSdkConversation(
                conversationId: 'conv-abc',
                userId: 'user-789',
                agentName: 'MyAgent'
            );

            expect($identity)->toBeInstanceOf(SessionIdentity::class);
            expect($identity->getAgentName())->toBe('MyAgent');
            expect($identity->getChatName())->toBe('conv-abc');
            expect($identity->getUserId())->toBe('user-789');
        });

        it('handles null userId', function () {
            $identity = SessionIdentityBridge::fromSdkConversation(
                conversationId: 'conv-abc',
                userId: null,
                agentName: 'MyAgent'
            );

            expect($identity->getUserId())->toBeNull();
            expect($identity->getChatName())->toBe('conv-abc');
        });

        it('handles integer userId by converting to string', function () {
            $identity = SessionIdentityBridge::fromSdkConversation(
                conversationId: 'conv-abc',
                userId: 42,
                agentName: 'MyAgent'
            );

            expect($identity->getUserId())->toBe('42');
        });
    });

    describe('toConversable', function () {
        it('returns array with conversationId and userId', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
            );

            $result = SessionIdentityBridge::toConversable($identity);

            expect($result)->toHaveKey('conversationId');
            expect($result)->toHaveKey('userId');
            expect($result['conversationId'])->toBe($identity->getKey());
            expect($result['userId'])->toBe('user-456');
        });

        it('returns null userId when not set', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-only',
            );

            $result = SessionIdentityBridge::toConversable($identity);

            expect($result['userId'])->toBeNull();
        });
    });

    describe('roundTrip', function () {
        it('preserves agent name through round-trip', function () {
            $original = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
            );

            $roundTripped = SessionIdentityBridge::roundTrip($original, 'TestAgent');

            expect($roundTripped->getAgentName())->toBe('TestAgent');
            expect($roundTripped->getUserId())->toBe('user-456');
        });

        it('uses composite key as chatName in round-trip', function () {
            $original = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
            );

            $roundTripped = SessionIdentityBridge::roundTrip($original, 'TestAgent');

            // After round-trip, chatName becomes the original composite key
            expect($roundTripped->getChatName())->toBe($original->getKey());
        });
    });

    describe('group handling', function () {
        it('toSdkConversationId includes group in key', function () {
            $identity = new SessionIdentity(
                agentName: 'TestAgent',
                chatName: 'chat-123',
                userId: 'user-456',
                group: 'my-group',
            );

            $conversationId = SessionIdentityBridge::toSdkConversationId($identity);

            // Key format uses group instead of agentName when group is set
            expect($conversationId)->toBe($identity->getKey());
            expect($conversationId)->toContain('my-group');
        });
    });
});
