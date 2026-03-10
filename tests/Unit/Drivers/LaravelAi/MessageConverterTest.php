<?php

use LarAgent\Drivers\LaravelAi\MessageConverter;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;

describe('MessageConverter', function () {

    describe('extractInstructions', function () {
        it('extracts system message as instructions', function () {
            $systemMsg = new SystemMessage('You are a helpful assistant.');
            $userMsg = new UserMessage('Hello');

            [$instructions, $remaining] = MessageConverter::extractInstructions([$systemMsg, $userMsg]);

            expect($instructions)->toBe('You are a helpful assistant.');
            expect($remaining)->toHaveCount(1);
            expect($remaining[0])->toBe($userMsg);
        });

        it('returns null when no system message', function () {
            $userMsg = new UserMessage('Hello');

            [$instructions, $remaining] = MessageConverter::extractInstructions([$userMsg]);

            expect($instructions)->toBeNull();
            expect($remaining)->toHaveCount(1);
        });

        it('returns null and empty array for empty messages', function () {
            [$instructions, $remaining] = MessageConverter::extractInstructions([]);

            expect($instructions)->toBeNull();
            expect($remaining)->toBeEmpty();
        });

        it('does not mutate the original array', function () {
            $systemMsg = new SystemMessage('Instructions');
            $userMsg = new UserMessage('Hello');
            $original = [$systemMsg, $userMsg];

            MessageConverter::extractInstructions($original);

            expect($original)->toHaveCount(2);
            expect($original[0])->toBe($systemMsg);
        });

        it('only extracts the first system message', function () {
            $system1 = new SystemMessage('First instruction');
            $userMsg = new UserMessage('Hello');
            $system2 = new SystemMessage('Second instruction');

            [$instructions, $remaining] = MessageConverter::extractInstructions([$system1, $userMsg, $system2]);

            expect($instructions)->toBe('First instruction');
            expect($remaining)->toHaveCount(2);
        });
    });

    describe('extractLastUserMessage', function () {
        it('extracts the last user message', function () {
            $messages = [
                new UserMessage('First'),
                new AssistantMessage('Response'),
                new UserMessage('Second'),
            ];

            expect(MessageConverter::extractLastUserMessage($messages))->toBe('Second');
        });

        it('returns empty string when no user messages', function () {
            $messages = [
                new AssistantMessage('Response'),
            ];

            expect(MessageConverter::extractLastUserMessage($messages))->toBe('');
        });

        it('returns empty string for empty array', function () {
            expect(MessageConverter::extractLastUserMessage([]))->toBe('');
        });
    });

    describe('convertUsage', function () {
        it('converts object with camelCase properties', function () {
            $usage = (object) ['promptTokens' => 100, 'completionTokens' => 50];

            $result = MessageConverter::convertUsage($usage);

            expect($result)->toBe([
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ]);
        });

        it('converts object with snake_case properties', function () {
            $usage = (object) ['prompt_tokens' => 200, 'completion_tokens' => 75];

            $result = MessageConverter::convertUsage($usage);

            expect($result)->toBe([
                'prompt_tokens' => 200,
                'completion_tokens' => 75,
                'total_tokens' => 275,
            ]);
        });

        it('converts array with camelCase keys', function () {
            $usage = ['promptTokens' => 100, 'completionTokens' => 50];

            $result = MessageConverter::convertUsage($usage);

            expect($result)->toBe([
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ]);
        });

        it('converts array with snake_case keys', function () {
            $usage = ['prompt_tokens' => 300, 'completion_tokens' => 100];

            $result = MessageConverter::convertUsage($usage);

            expect($result)->toBe([
                'prompt_tokens' => 300,
                'completion_tokens' => 100,
                'total_tokens' => 400,
            ]);
        });

        it('returns empty array for null usage', function () {
            expect(MessageConverter::convertUsage(null))->toBe([]);
        });

        it('returns empty array for non-object non-array', function () {
            expect(MessageConverter::convertUsage('invalid'))->toBe([]);
        });

        it('defaults missing token counts to zero', function () {
            $usage = (object) [];

            $result = MessageConverter::convertUsage($usage);

            expect($result)->toBe([
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ]);
        });
    });

    describe('fromSdkResponse', function () {
        it('creates assistant message from response with text', function () {
            $response = (object) ['text' => 'Hello from the SDK'];

            $message = MessageConverter::fromSdkResponse($response);

            expect($message)->toBeInstanceOf(AssistantMessage::class);
            expect($message->getContentAsString())->toBe('Hello from the SDK');
        });

        it('creates assistant message with empty content when text is missing', function () {
            $response = (object) [];

            $message = MessageConverter::fromSdkResponse($response);

            expect($message)->toBeInstanceOf(AssistantMessage::class);
            expect($message->getContentAsString())->toBe('');
        });

        it('includes usage when available', function () {
            $response = (object) [
                'text' => 'Hello',
                'usage' => (object) ['promptTokens' => 10, 'completionTokens' => 5],
            ];

            $message = MessageConverter::fromSdkResponse($response);

            expect($message->getUsage())->not->toBeNull();
            expect($message->getUsage()->promptTokens)->toBe(10);
            expect($message->getUsage()->completionTokens)->toBe(5);
        });
    });

    describe('extractIntermediateMessages', function () {
        it('returns empty array when no steps', function () {
            $response = (object) ['text' => 'Hello'];

            expect(MessageConverter::extractIntermediateMessages($response))->toBeEmpty();
        });

        it('returns empty array when steps is empty', function () {
            $response = (object) ['text' => 'Hello', 'steps' => []];

            expect(MessageConverter::extractIntermediateMessages($response))->toBeEmpty();
        });

        it('creates tool call and result messages from steps', function () {
            $response = (object) [
                'text' => 'The weather is sunny',
                'steps' => [
                    (object) [
                        'toolName' => 'get_weather',
                        'toolArgs' => ['location' => 'NYC'],
                        'toolResult' => 'Sunny, 72F',
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            expect($messages)->toHaveCount(2);
            expect($messages[0])->toBeInstanceOf(ToolCallMessage::class);
            expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);

            // Verify tool call details
            $toolCalls = $messages[0]->getToolCalls();
            expect($toolCalls)->toHaveCount(1);
            expect($toolCalls[0]->getToolName())->toBe('get_weather');

            // Verify tool result
            expect($messages[1]->getContentAsString())->toBe('Sunny, 72F');
        });

        it('handles multiple steps', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'search',
                        'toolArgs' => ['query' => 'test'],
                        'toolResult' => 'Results found',
                    ],
                    (object) [
                        'toolName' => 'analyze',
                        'toolArgs' => ['data' => 'test data'],
                        'toolResult' => 'Analysis complete',
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            // 2 steps × 2 messages each (tool call + tool result) = 4
            expect($messages)->toHaveCount(4);
        });

        it('handles string tool args as valid JSON', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'test_tool',
                        'toolArgs' => '{"key": "value"}',
                        'toolResult' => 'ok',
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            expect($messages)->toHaveCount(2);
            $toolCalls = $messages[0]->getToolCalls();
            expect(json_decode($toolCalls[0]->getArguments(), true))->toBe(['key' => 'value']);
        });

        it('wraps invalid JSON string args', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'test_tool',
                        'toolArgs' => 'not json',
                        'toolResult' => 'ok',
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            expect($messages)->toHaveCount(2);
            $toolCalls = $messages[0]->getToolCalls();
            $decoded = json_decode($toolCalls[0]->getArguments(), true);
            expect($decoded)->toHaveKey('value');
            expect($decoded['value'])->toBe('not json');
        });

        it('attaches step usage to tool result messages as metadata', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'search',
                        'toolArgs' => ['query' => 'test'],
                        'toolResult' => 'Results',
                        'usage' => (object) ['promptTokens' => 100, 'completionTokens' => 50],
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            // ToolResultMessage is at index 1
            expect($messages[1]->hasExtra('step_usage'))->toBeTrue();
            $stepUsage = $messages[1]->getExtra('step_usage');
            expect($stepUsage['prompt_tokens'])->toBe(100);
            expect($stepUsage['completion_tokens'])->toBe(50);
            expect($stepUsage['total_tokens'])->toBe(150);
        });

        it('does not attach usage when step has no usage', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'search',
                        'toolArgs' => ['query' => 'test'],
                        'toolResult' => 'Results',
                    ],
                ],
            ];

            $messages = MessageConverter::extractIntermediateMessages($response);

            expect($messages[1]->hasExtra('step_usage'))->toBeFalse();
        });
    });

    describe('aggregateStepUsage', function () {
        it('aggregates usage from multiple steps plus final response', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'tool1',
                        'toolArgs' => [],
                        'toolResult' => 'ok',
                        'usage' => (object) ['promptTokens' => 100, 'completionTokens' => 50],
                    ],
                    (object) [
                        'toolName' => 'tool2',
                        'toolArgs' => [],
                        'toolResult' => 'ok',
                        'usage' => (object) ['promptTokens' => 200, 'completionTokens' => 75],
                    ],
                ],
                'usage' => (object) ['promptTokens' => 300, 'completionTokens' => 100],
            ];

            $usage = MessageConverter::aggregateStepUsage($response);

            expect($usage)->not->toBeNull();
            // Sum: 100+200+300 = 600 prompt, 50+75+100 = 225 completion
            expect($usage->promptTokens)->toBe(600);
            expect($usage->completionTokens)->toBe(225);
            expect($usage->totalTokens)->toBe(825);
        });

        it('returns null when no usage data exists', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'tool1',
                        'toolArgs' => [],
                        'toolResult' => 'ok',
                    ],
                ],
            ];

            expect(MessageConverter::aggregateStepUsage($response))->toBeNull();
        });

        it('handles response with only final usage (no steps)', function () {
            $response = (object) [
                'text' => 'Hello',
                'usage' => (object) ['promptTokens' => 50, 'completionTokens' => 25],
            ];

            $usage = MessageConverter::aggregateStepUsage($response);

            expect($usage)->not->toBeNull();
            expect($usage->promptTokens)->toBe(50);
            expect($usage->completionTokens)->toBe(25);
            expect($usage->totalTokens)->toBe(75);
        });

        it('handles steps with usage but no final response usage', function () {
            $response = (object) [
                'text' => 'Done',
                'steps' => [
                    (object) [
                        'toolName' => 'tool1',
                        'toolArgs' => [],
                        'toolResult' => 'ok',
                        'usage' => (object) ['promptTokens' => 100, 'completionTokens' => 50],
                    ],
                ],
            ];

            $usage = MessageConverter::aggregateStepUsage($response);

            expect($usage)->not->toBeNull();
            expect($usage->promptTokens)->toBe(100);
            expect($usage->completionTokens)->toBe(50);
            expect($usage->totalTokens)->toBe(150);
        });

        it('handles empty steps array', function () {
            $response = (object) [
                'text' => 'Hello',
                'steps' => [],
                'usage' => (object) ['promptTokens' => 10, 'completionTokens' => 5],
            ];

            $usage = MessageConverter::aggregateStepUsage($response);

            expect($usage)->not->toBeNull();
            expect($usage->totalTokens)->toBe(15);
        });
    });

    describe('fromSdkResponse with aggregated usage', function () {
        it('uses aggregated step usage on final message', function () {
            $response = (object) [
                'text' => 'Weather is sunny.',
                'steps' => [
                    (object) [
                        'toolName' => 'get_weather',
                        'toolArgs' => ['city' => 'NYC'],
                        'toolResult' => 'Sunny',
                        'usage' => (object) ['promptTokens' => 100, 'completionTokens' => 50],
                    ],
                ],
                'usage' => (object) ['promptTokens' => 200, 'completionTokens' => 80],
            ];

            $message = MessageConverter::fromSdkResponse($response);

            // Should have aggregated total: 100+200=300 prompt, 50+80=130 completion
            expect($message->getUsage())->not->toBeNull();
            expect($message->getUsage()->promptTokens)->toBe(300);
            expect($message->getUsage()->completionTokens)->toBe(130);
            expect($message->getUsage()->totalTokens)->toBe(430);
        });

        it('falls back to final usage when no steps', function () {
            $response = (object) [
                'text' => 'Hello',
                'usage' => (object) ['promptTokens' => 50, 'completionTokens' => 25],
            ];

            $message = MessageConverter::fromSdkResponse($response);

            expect($message->getUsage())->not->toBeNull();
            expect($message->getUsage()->promptTokens)->toBe(50);
            expect($message->getUsage()->completionTokens)->toBe(25);
        });
    });
});
