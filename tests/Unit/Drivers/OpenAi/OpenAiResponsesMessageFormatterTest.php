<?php

use LarAgent\Drivers\OpenAi\OpenAiResponsesMessageFormatter;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\ToolCall;

beforeEach(function () {
    $this->formatter = new OpenAiResponsesMessageFormatter;
});

describe('OpenAiResponsesMessageFormatter', function () {
    describe('formatMessage()', function () {
        it('formats a UserMessage as Responses API input item', function () {
            $message = new UserMessage('Hello');
            $result = $this->formatter->formatMessage($message);

            expect($result)->toHaveKey('type', 'message');
            expect($result)->toHaveKey('role', 'user');
            expect($result['content'])->toBeArray();
            expect($result['content'][0])->toBe(['type' => 'input_text', 'text' => 'Hello']);
        });

        it('formats a SystemMessage with input_text content type', function () {
            $message = new SystemMessage('You are helpful.');
            $result = $this->formatter->formatMessage($message);

            expect($result)->toHaveKey('type', 'message');
            expect($result)->toHaveKey('role', 'system');
            expect($result['content'][0])->toBe(['type' => 'input_text', 'text' => 'You are helpful.']);
        });

        it('formats a DeveloperMessage with input_text content type', function () {
            $message = new DeveloperMessage('Be concise.');
            $result = $this->formatter->formatMessage($message);

            expect($result)->toHaveKey('type', 'message');
            expect($result)->toHaveKey('role', 'developer');
            expect($result['content'][0])->toBe(['type' => 'input_text', 'text' => 'Be concise.']);
        });

        it('formats an AssistantMessage with output_text content type', function () {
            $message = new AssistantMessage('Sure, I can help.');
            $result = $this->formatter->formatMessage($message);

            expect($result)->toHaveKey('type', 'message');
            expect($result)->toHaveKey('role', 'assistant');
            expect($result['content'][0])->toBe(['type' => 'output_text', 'text' => 'Sure, I can help.']);
        });

        it('formats a ToolResultMessage as function_call_output', function () {
            $message = new ToolResultMessage('{"result": "ok"}', 'call_123', 'tool_name');
            $result = $this->formatter->formatMessage($message);

            expect($result)->toHaveKey('type', 'function_call_output');
            expect($result)->toHaveKey('call_id', 'call_123');
            expect($result)->toHaveKey('output');
        });
    });

    describe('formatMessage() with images', function () {
        it('maps image_url content type to input_image for user messages', function () {
            // Create a user message with multimodal content via fromArray
            $message = UserMessage::fromArray([
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is in this image?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.png']],
                ],
            ]);
            $result = $this->formatter->formatMessage($message);

            expect($result['content'])->toHaveCount(2);
            expect($result['content'][0])->toBe(['type' => 'input_text', 'text' => 'What is in this image?']);
            expect($result['content'][1]['type'])->toBe('input_image');
            expect($result['content'][1]['image_url'])->toBe('https://example.com/img.png');
        });
    });

    describe('formatMessages()', function () {
        it('flattens ToolCallMessage into separate function_call items', function () {
            $toolCalls = [
                new ToolCall('call_1', 'get_weather', '{"city": "NYC"}'),
                new ToolCall('call_2', 'get_time', '{"zone": "EST"}'),
            ];
            $toolCallMessage = new ToolCallMessage($toolCalls);

            $result = $this->formatter->formatMessages([$toolCallMessage]);

            expect($result)->toHaveCount(2);
            expect($result[0])->toHaveKey('type', 'function_call');
            expect($result[0])->toHaveKey('call_id', 'call_1');
            expect($result[0])->toHaveKey('name', 'get_weather');
            expect($result[0])->toHaveKey('arguments', '{"city": "NYC"}');
            expect($result[1])->toHaveKey('type', 'function_call');
            expect($result[1])->toHaveKey('call_id', 'call_2');
            expect($result[1])->toHaveKey('name', 'get_time');
        });

        it('formats mixed message types correctly', function () {
            $messages = [
                new SystemMessage('You are helpful.'),
                new UserMessage('Hello'),
                new AssistantMessage('Hi there!'),
            ];

            $result = $this->formatter->formatMessages($messages);

            expect($result)->toHaveCount(3);
            expect($result[0]['role'])->toBe('system');
            expect($result[1]['role'])->toBe('user');
            expect($result[2]['role'])->toBe('assistant');
        });
    });

    describe('extractToolCalls()', function () {
        it('extracts function_call items from output', function () {
            $response = [
                'output' => [
                    [
                        'type' => 'function_call',
                        'call_id' => 'call_abc',
                        'name' => 'get_weather',
                        'arguments' => '{"city": "London"}',
                    ],
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'Let me check.']],
                    ],
                    [
                        'type' => 'function_call',
                        'call_id' => 'call_def',
                        'name' => 'get_time',
                        'arguments' => '{"zone": "UTC"}',
                    ],
                ],
            ];

            $toolCalls = $this->formatter->extractToolCalls($response);

            expect($toolCalls)->toHaveCount(2);
            expect($toolCalls[0]->getId())->toBe('call_abc');
            expect($toolCalls[0]->getToolName())->toBe('get_weather');
            expect($toolCalls[0]->getArguments())->toBe('{"city": "London"}');
            expect($toolCalls[1]->getId())->toBe('call_def');
            expect($toolCalls[1]->getToolName())->toBe('get_time');
        });

        it('returns empty array when no function_call items', function () {
            $response = [
                'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => []],
                ],
            ];

            expect($this->formatter->extractToolCalls($response))->toBeEmpty();
        });
    });

    describe('extractContent()', function () {
        it('extracts output_text from response', function () {
            $response = [
                'output_text' => 'Hello, how can I help?',
            ];

            expect($this->formatter->extractContent($response))->toBe('Hello, how can I help?');
        });

        it('returns empty string when output_text is missing', function () {
            expect($this->formatter->extractContent([]))->toBe('');
        });
    });

    describe('extractFinishReason()', function () {
        it('returns tool_calls when output contains function_call items', function () {
            $response = [
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'test', 'arguments' => '{}'],
                ],
            ];

            expect($this->formatter->extractFinishReason($response))->toBe('tool_calls');
        });

        it('maps completed status to stop', function () {
            $response = [
                'status' => 'completed',
                'output' => [
                    ['type' => 'message', 'role' => 'assistant'],
                ],
            ];

            expect($this->formatter->extractFinishReason($response))->toBe('stop');
        });

        it('maps incomplete status to length', function () {
            $response = [
                'status' => 'incomplete',
                'output' => [],
            ];

            expect($this->formatter->extractFinishReason($response))->toBe('length');
        });
    });

    describe('extractOutputItemsForReplay()', function () {
        it('extracts reasoning and function_call items from output', function () {
            $response = [
                'output' => [
                    [
                        'id' => 'rs_abc123',
                        'type' => 'reasoning',
                        'status' => 'completed',
                        'encrypted_content' => 'encrypted_data_here',
                        'summary' => [['type' => 'summary_text', 'text' => 'thinking...']],
                    ],
                    [
                        'id' => 'fc_def456',
                        'type' => 'function_call',
                        'status' => 'completed',
                        'call_id' => 'call_abc',
                        'name' => 'get_weather',
                        'arguments' => '{"city": "London"}',
                    ],
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'ignored']],
                    ],
                ],
            ];

            $items = $this->formatter->extractOutputItemsForReplay($response);

            expect($items)->toHaveCount(2);
            // Reasoning item - all non-null fields preserved
            expect($items[0]['type'])->toBe('reasoning');
            expect($items[0]['id'])->toBe('rs_abc123');
            expect($items[0]['encrypted_content'])->toBe('encrypted_data_here');
            expect($items[0]['status'])->toBe('completed');
            // Function call item - all non-null fields preserved
            expect($items[1]['type'])->toBe('function_call');
            expect($items[1]['id'])->toBe('fc_def456');
            expect($items[1]['call_id'])->toBe('call_abc');
            expect($items[1]['status'])->toBe('completed');
        });

        it('filters out null values from output items', function () {
            $response = [
                'output' => [
                    [
                        'id' => 'rs_abc',
                        'type' => 'reasoning',
                        'encrypted_content' => null,
                        'status' => null,
                        'summary' => [],
                    ],
                ],
            ];

            $items = $this->formatter->extractOutputItemsForReplay($response);

            expect($items)->toHaveCount(1);
            expect($items[0])->not->toHaveKey('encrypted_content');
            expect($items[0])->not->toHaveKey('status');
            expect($items[0])->toHaveKey('id', 'rs_abc');
            expect($items[0])->toHaveKey('type', 'reasoning');
        });

        it('returns empty array when no reasoning or function_call items', function () {
            $response = [
                'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => []],
                ],
            ];

            expect($this->formatter->extractOutputItemsForReplay($response))->toBeEmpty();
        });
    });

    describe('formatMessages() with raw output items', function () {
        it('replays raw output items when available on ToolCallMessage', function () {
            $toolCalls = [
                new ToolCall('call_1', 'get_weather', '{"city": "NYC"}'),
            ];
            $toolCallMessage = new ToolCallMessage($toolCalls);
            $toolCallMessage->setExtra('raw_output_items', [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_abc',
                    'encrypted_content' => 'encrypted_data',
                    'summary' => [['type' => 'summary_text', 'text' => 'thinking']],
                ],
                [
                    'type' => 'function_call',
                    'id' => 'fc_def',
                    'call_id' => 'call_1',
                    'name' => 'get_weather',
                    'arguments' => '{"city": "NYC"}',
                ],
            ]);

            $result = $this->formatter->formatMessages([$toolCallMessage]);

            expect($result)->toHaveCount(2);
            expect($result[0]['type'])->toBe('reasoning');
            expect($result[0]['id'])->toBe('rs_abc');
            expect($result[0])->toHaveKey('encrypted_content');
            expect($result[1]['type'])->toBe('function_call');
            expect($result[1]['id'])->toBe('fc_def');
        });

        it('falls back to reconstructed function_call items without raw output', function () {
            $toolCalls = [
                new ToolCall('call_1', 'get_weather', '{"city": "NYC"}'),
            ];
            $toolCallMessage = new ToolCallMessage($toolCalls);

            $result = $this->formatter->formatMessages([$toolCallMessage]);

            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('function_call');
            expect($result[0]['call_id'])->toBe('call_1');
            // Reconstructed items don't have the response item id
            expect($result[0])->not->toHaveKey('id');
        });
    });

    describe('extractUsage()', function () {
        it('maps input_tokens and output_tokens to normalized keys', function () {
            $response = [
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'total_tokens' => 150,
                ],
            ];

            $usage = $this->formatter->extractUsage($response);

            expect($usage['prompt_tokens'])->toBe(100);
            expect($usage['completion_tokens'])->toBe(50);
            expect($usage['total_tokens'])->toBe(150);
        });

        it('computes total_tokens if missing', function () {
            $response = [
                'usage' => [
                    'input_tokens' => 80,
                    'output_tokens' => 20,
                ],
            ];

            $usage = $this->formatter->extractUsage($response);

            expect($usage['total_tokens'])->toBe(100);
        });

        it('returns zeros when usage is missing', function () {
            $usage = $this->formatter->extractUsage([]);

            expect($usage['prompt_tokens'])->toBe(0);
            expect($usage['completion_tokens'])->toBe(0);
            expect($usage['total_tokens'])->toBe(0);
        });
    });
});
