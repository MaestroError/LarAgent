<?php

use LarAgent\Drivers\LaravelAi\LarAgentConversationStore;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\UserMessage;

describe('LarAgentConversationStore', function () {

    describe('storeConversation', function () {
        it('returns a non-empty conversation ID', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);

            $conversationId = $store->storeConversation('user-123', 'Test Conversation');

            expect($conversationId)->toBeString();
            expect($conversationId)->not->toBeEmpty();
        });

        it('returns different IDs for different conversations', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);

            $id1 = $store->storeConversation('user-123', 'Conversation 1');
            $id2 = $store->storeConversation('user-123', 'Conversation 2');

            expect($id1)->not->toBe($id2);
        });

        it('handles null userId', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);

            $conversationId = $store->storeConversation(null, 'Anonymous Conversation');

            expect($conversationId)->toBeString();
            expect($conversationId)->not->toBeEmpty();
        });
    });

    describe('storeUserMessage', function () {
        it('stores a user message and returns a message ID', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['text' => 'Hello, world!'];
            $messageId = $store->storeUserMessage($conversationId, 'user-123', $prompt);

            expect($messageId)->toBeString();
            expect($messageId)->not->toBeEmpty();
        });

        it('message is retrievable after storage', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['text' => 'Hello, world!'];
            $store->storeUserMessage($conversationId, 'user-123', $prompt);

            $messages = $store->getLatestConversationMessages($conversationId, 10);

            expect($messages)->toHaveCount(1);
            expect($messages->first())->toBeInstanceOf(UserMessage::class);
            expect($messages->first()->getContentAsString())->toBe('Hello, world!');
        });
    });

    describe('storeAssistantMessage', function () {
        it('stores assistant message from SDK response', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['text' => 'What is AI?'];
            $response = (object) [
                'text' => 'AI is artificial intelligence.',
                'usage' => (object) ['promptTokens' => 10, 'completionTokens' => 5],
            ];

            $messageId = $store->storeAssistantMessage($conversationId, 'user-123', $prompt, $response);

            expect($messageId)->toBeString();
            expect($messageId)->not->toBeEmpty();
        });

        it('stores intermediate tool messages from response with steps', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['text' => 'What is the weather?'];
            $response = (object) [
                'text' => 'The weather is sunny.',
                'steps' => [
                    (object) [
                        'toolName' => 'get_weather',
                        'toolArgs' => ['location' => 'NYC'],
                        'toolResult' => 'Sunny, 72F',
                    ],
                ],
                'usage' => (object) ['promptTokens' => 20, 'completionTokens' => 10],
            ];

            $store->storeAssistantMessage($conversationId, 'user-123', $prompt, $response);

            $messages = $store->getLatestConversationMessages($conversationId, 10);

            // Should have: ToolCallMessage + ToolResultMessage + AssistantMessage = 3
            expect($messages)->toHaveCount(3);
        });
    });

    describe('getLatestConversationMessages', function () {
        it('returns empty collection for new conversation', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Empty');

            $messages = $store->getLatestConversationMessages($conversationId, 10);

            expect($messages)->toBeEmpty();
        });

        it('respects the limit parameter', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            // Store 3 user messages
            for ($i = 1; $i <= 3; $i++) {
                $store->storeUserMessage($conversationId, 'user-123', (object) ['text' => "Message $i"]);
            }

            $limited = $store->getLatestConversationMessages($conversationId, 2);

            expect($limited)->toHaveCount(2);
            // Should be the last 2 messages
            expect($limited->last()->getContentAsString())->toBe('Message 3');
        });
    });

    describe('getChatHistory', function () {
        it('returns a ChatHistoryStorage instance', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $chatHistory = $store->getChatHistory($conversationId, 'user-123');

            expect($chatHistory)->toBeInstanceOf(\LarAgent\Context\Storages\ChatHistoryStorage::class);
        });
    });

    describe('extractPromptContent', function () {
        it('handles prompt with text property', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['text' => 'Hello from text'];
            $store->storeUserMessage($conversationId, 'user-123', $prompt);

            $messages = $store->getLatestConversationMessages($conversationId, 1);
            expect($messages->first()->getContentAsString())->toBe('Hello from text');
        });

        it('handles prompt with content property', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) ['content' => 'Hello from content'];
            $store->storeUserMessage($conversationId, 'user-123', $prompt);

            $messages = $store->getLatestConversationMessages($conversationId, 1);
            expect($messages->first()->getContentAsString())->toBe('Hello from content');
        });

        it('handles empty prompt gracefully', function () {
            $store = new LarAgentConversationStore('TestAgent', [\LarAgent\Context\Drivers\InMemoryStorage::class]);
            $conversationId = $store->storeConversation('user-123', 'Test');

            $prompt = (object) [];
            $store->storeUserMessage($conversationId, 'user-123', $prompt);

            $messages = $store->getLatestConversationMessages($conversationId, 1);
            expect($messages->first()->getContentAsString())->toBe('');
        });
    });
});
