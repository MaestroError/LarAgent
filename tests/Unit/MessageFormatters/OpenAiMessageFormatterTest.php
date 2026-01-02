<?php

use LarAgent\Drivers\OpenAi\OpenAiMessageFormatter;
use LarAgent\Message;
use LarAgent\Tool;
use LarAgent\ToolCall;

describe('OpenAiMessageFormatter', function () {
    beforeEach(function () {
        $this->formatter = new OpenAiMessageFormatter;
    });

    // ========== formatMessage Tests ==========

    it('formats user message', function () {
        $message = Message::user('Hello, world!');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['content'])->toBeArray();
    });

    it('formats user message with text content', function () {
        $message = Message::user('Hello, world!');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['content'][0]['type'])->toBe('text')
            ->and($formatted['content'][0]['text'])->toBe('Hello, world!');
    });

    it('formats assistant message', function () {
        $message = Message::assistant('I can help you with that.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('assistant')
            ->and($formatted['content'])->toBe('I can help you with that.');
    });

    it('formats system message', function () {
        $message = Message::system('You are a helpful assistant.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('system')
            ->and($formatted['content'])->toBe('You are a helpful assistant.');
    });

    it('formats developer message', function () {
        $message = Message::developer('Developer instructions here.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('developer')
            ->and($formatted['content'])->toBe('Developer instructions here.');
    });

    it('formats tool call message', function () {
        $toolCall = new ToolCall('call_123', 'get_weather', '{"city": "London"}');
        $message = Message::toolCall([$toolCall]);
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('assistant')
            ->and($formatted['content'])->toBeNull()
            ->and($formatted['tool_calls'])->toBeArray()
            ->and($formatted['tool_calls'][0]['id'])->toBe('call_123')
            ->and($formatted['tool_calls'][0]['type'])->toBe('function')
            ->and($formatted['tool_calls'][0]['function']['name'])->toBe('get_weather')
            ->and($formatted['tool_calls'][0]['function']['arguments'])->toBe('{"city": "London"}');
    });

    it('formats tool result message', function () {
        $message = Message::toolResult('Sunny, 22°C', 'call_123', 'get_weather');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('tool')
            ->and($formatted['content'])->toBe('Sunny, 22°C')
            ->and($formatted['tool_call_id'])->toBe('call_123');
    });

    // ========== formatMessages Tests ==========

    it('formats array of messages', function () {
        $messages = [
            Message::system('You are helpful.'),
            Message::user('Hello'),
            Message::assistant('Hi there!'),
        ];

        $formatted = $this->formatter->formatMessages($messages);

        expect($formatted)->toHaveCount(3)
            ->and($formatted[0]['role'])->toBe('system')
            ->and($formatted[1]['role'])->toBe('user')
            ->and($formatted[2]['role'])->toBe('assistant');
    });

    // ========== formatTools Tests ==========

    it('formats tools for OpenAI', function () {
        $tool = Tool::create('get_weather', 'Get weather for a city')
            ->addProperty('city', 'string', 'The city name')
            ->setRequired('city');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted)->toHaveCount(1)
            ->and($formatted[0]['type'])->toBe('function')
            ->and($formatted[0]['function']['name'])->toBe('get_weather')
            ->and($formatted[0]['function']['description'])->toBe('Get weather for a city')
            ->and($formatted[0]['function']['parameters']['type'])->toBe('object')
            ->and($formatted[0]['function']['parameters']['properties'])->toHaveKey('city')
            ->and($formatted[0]['function']['parameters']['required'])->toBe(['city']);
    });

    it('formats tool without properties', function () {
        $tool = Tool::create('get_time', 'Get current time');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted[0]['function']['name'])->toBe('get_time')
            ->and($formatted[0]['function'])->not->toHaveKey('parameters');
    });

    // ========== extractUsage Tests ==========

    it('extracts usage from OpenAI response', function () {
        $response = [
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
        ];

        $usage = $this->formatter->extractUsage($response);

        expect($usage['prompt_tokens'])->toBe(100)
            ->and($usage['completion_tokens'])->toBe(50)
            ->and($usage['total_tokens'])->toBe(150);
    });

    it('returns zero usage when not present', function () {
        $response = [];

        $usage = $this->formatter->extractUsage($response);

        expect($usage['prompt_tokens'])->toBe(0)
            ->and($usage['completion_tokens'])->toBe(0)
            ->and($usage['total_tokens'])->toBe(0);
    });

    // ========== extractToolCalls Tests ==========

    it('extracts tool calls from OpenAI response', function () {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"London"}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0])->toBeInstanceOf(ToolCall::class)
            ->and($toolCalls[0]->getId())->toBe('call_abc123')
            ->and($toolCalls[0]->getToolName())->toBe('get_weather')
            ->and($toolCalls[0]->getArguments())->toBe('{"city":"London"}');
    });

    it('returns empty array when no tool calls', function () {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Hello!',
                    ],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toBeEmpty();
    });

    // ========== extractContent Tests ==========

    it('extracts content from OpenAI response', function () {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Hello, how can I help you?',
                    ],
                ],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('Hello, how can I help you?');
    });

    it('returns empty string when content is null', function () {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => null,
                    ],
                ],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('');
    });

    // ========== extractFinishReason Tests ==========

    it('extracts finish reason from OpenAI response', function () {
        $response = [
            'choices' => [
                [
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('stop');
    });

    it('extracts tool_calls finish reason', function () {
        $response = [
            'choices' => [
                [
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('tool_calls');
    });

    it('defaults to stop when finish reason missing', function () {
        $response = [
            'choices' => [[]],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('stop');
    });
});
