<?php

use LarAgent\Context\Context;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Drivers\LaravelAi\MessageConverter;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\UserMessage;

describe('SDK Truncation Integration', function () {

    it('truncation finds usage on SDK response with steps', function () {
        // Simulate what happens when SDK returns a response with multiple tool steps
        $response = (object) [
            'text' => 'The weather is sunny in NYC and the temperature is 72F.',
            'steps' => [
                (object) [
                    'toolName' => 'get_weather',
                    'toolArgs' => ['location' => 'NYC'],
                    'toolResult' => 'Sunny, 72F',
                    'usage' => (object) ['promptTokens' => 500, 'completionTokens' => 100],
                ],
                (object) [
                    'toolName' => 'get_temperature',
                    'toolArgs' => ['location' => 'NYC'],
                    'toolResult' => '72F',
                    'usage' => (object) ['promptTokens' => 600, 'completionTokens' => 100],
                ],
            ],
            'usage' => (object) ['promptTokens' => 700, 'completionTokens' => 200],
        ];

        // Convert response (this is what LaravelAiDriver does)
        $assistantMessage = MessageConverter::fromSdkResponse($response);

        // The assistant message should have aggregated usage
        expect($assistantMessage->getUsage())->not->toBeNull();

        // Aggregated: 500+600+700 = 1800 prompt, 100+100+200 = 400 completion
        expect($assistantMessage->getUsage()->totalTokens)->toBe(2200);
    });

    it('intermediate messages carry per-step usage as metadata', function () {
        $response = (object) [
            'text' => 'Done',
            'steps' => [
                (object) [
                    'toolName' => 'search',
                    'toolArgs' => ['q' => 'test'],
                    'toolResult' => 'found',
                    'usage' => (object) ['promptTokens' => 300, 'completionTokens' => 50],
                ],
            ],
            'usage' => (object) ['promptTokens' => 400, 'completionTokens' => 100],
        ];

        $intermediateMessages = MessageConverter::extractIntermediateMessages($response);

        // ToolResultMessage should have step_usage metadata
        $toolResult = $intermediateMessages[1];
        expect($toolResult->hasExtra('step_usage'))->toBeTrue();

        $stepUsage = $toolResult->getExtra('step_usage');
        expect($stepUsage['prompt_tokens'])->toBe(300);
        expect($stepUsage['completion_tokens'])->toBe(50);
        expect($stepUsage['total_tokens'])->toBe(350);
    });

    it('context truncation triggers with high cumulative usage from SDK', function () {
        $driversConfig = [InMemoryStorage::class];

        $identity = new SessionIdentity(
            agentName: 'TruncTestAgent',
            chatName: 'test-session',
        );

        $context = new Context($identity, $driversConfig);
        $chatHistory = new ChatHistoryStorage($identity, $driversConfig);
        $context->register($chatHistory);

        // Set up truncation with a low threshold
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2, 'preserve_system' => false]);
        $context->setTruncationStrategy($strategy);
        $context->setTruncationThreshold(1000);
        $context->setTruncationBuffer(0.2); // Effective threshold: 800

        // Add multiple messages to simulate a conversation
        $chatHistory->addMessage(new UserMessage('First message'));
        $chatHistory->addMessage(new AssistantMessage('First response'));
        $chatHistory->addMessage(new UserMessage('Second message'));
        $chatHistory->addMessage(new AssistantMessage('Second response'));
        $chatHistory->addMessage(new UserMessage('Third message'));

        // Simulate an SDK response with high cumulative usage
        $response = (object) [
            'text' => 'Final response with high usage',
            'steps' => [
                (object) [
                    'toolName' => 'expensive_tool',
                    'toolArgs' => [],
                    'toolResult' => 'result',
                    'usage' => (object) ['promptTokens' => 400, 'completionTokens' => 100],
                ],
            ],
            'usage' => (object) ['promptTokens' => 500, 'completionTokens' => 150],
        ];

        $assistantMessage = MessageConverter::fromSdkResponse($response);
        $chatHistory->addMessage($assistantMessage);

        // Usage is 400+500=900 prompt + 100+150=250 completion = 1150 total
        $currentTokens = $assistantMessage->getUsage()->totalTokens;
        expect($currentTokens)->toBe(1150);

        // Apply truncation — should trigger because 1150 > 800 (effective threshold)
        $messageCountBefore = $chatHistory->getMessages()->count();
        $context->applyTruncation($chatHistory, $currentTokens);

        // After truncation, should keep only 2 messages (keep_messages=2)
        expect($chatHistory->getMessages()->count())->toBeLessThan($messageCountBefore);
        expect($chatHistory->getMessages()->count())->toBe(2);
    });

    it('truncation does not trigger when usage is below threshold', function () {
        $driversConfig = [InMemoryStorage::class];

        $identity = new SessionIdentity(
            agentName: 'TruncTestAgent',
            chatName: 'low-usage-test',
        );

        $context = new Context($identity, $driversConfig);
        $chatHistory = new ChatHistoryStorage($identity, $driversConfig);
        $context->register($chatHistory);

        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2, 'preserve_system' => false]);
        $context->setTruncationStrategy($strategy);
        $context->setTruncationThreshold(5000);
        $context->setTruncationBuffer(0.2); // Effective threshold: 4000

        $chatHistory->addMessage(new UserMessage('Hello'));
        $chatHistory->addMessage(new AssistantMessage('Hi there'));
        $chatHistory->addMessage(new UserMessage('How are you?'));

        // Low usage SDK response
        $response = (object) [
            'text' => 'I am fine!',
            'usage' => (object) ['promptTokens' => 50, 'completionTokens' => 20],
        ];

        $assistantMessage = MessageConverter::fromSdkResponse($response);
        $chatHistory->addMessage($assistantMessage);

        $currentTokens = $assistantMessage->getUsage()->totalTokens;
        expect($currentTokens)->toBe(70);

        $messageCountBefore = $chatHistory->getMessages()->count();
        $context->applyTruncation($chatHistory, $currentTokens);

        // No truncation should happen
        expect($chatHistory->getMessages()->count())->toBe($messageCountBefore);
    });
});
