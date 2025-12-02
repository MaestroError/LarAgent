<?php

use LarAgent\Drivers\Anthropic\ClaudeMessageFormatter;
use LarAgent\Message;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\Messages\UserMessage;
use LarAgent\Tool;
use LarAgent\ToolCall;

describe('ClaudeMessageFormatter', function () {
    beforeEach(function () {
        $this->formatter = new ClaudeMessageFormatter();
    });

    // ========== formatMessage Tests ==========

    it('formats user message with content blocks', function () {
        $message = Message::user('Hello, Claude!');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['content'])->toBeArray()
            ->and($formatted['content'][0]['type'])->toBe('text')
            ->and($formatted['content'][0]['text'])->toBe('Hello, Claude!');
    });

    it('formats assistant message with content blocks', function () {
        $message = Message::assistant('I can help you.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('assistant')
            ->and($formatted['content'])->toBeArray()
            ->and($formatted['content'][0]['type'])->toBe('text')
            ->and($formatted['content'][0]['text'])->toBe('I can help you.');
    });

    it('returns empty array for system message', function () {
        $message = Message::system('You are helpful.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted)->toBeEmpty();
    });

    it('returns empty array for developer message', function () {
        $message = Message::developer('Developer instructions.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted)->toBeEmpty();
    });

    it('formats tool call message with tool_use blocks', function () {
        $toolCall = new ToolCall('call_123', 'get_weather', '{"city": "Paris"}');
        $message = Message::toolCall([$toolCall]);
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('assistant')
            ->and($formatted['content'])->toBeArray()
            ->and($formatted['content'][0]['type'])->toBe('tool_use')
            ->and($formatted['content'][0]['id'])->toBe('call_123')
            ->and($formatted['content'][0]['name'])->toBe('get_weather')
            ->and($formatted['content'][0]['input'])->toBe(['city' => 'Paris']);
    });

    it('formats tool result message with tool_result block', function () {
        $message = Message::toolResult('Sunny, 25°C', 'call_123', 'get_weather');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['content'])->toBeArray()
            ->and($formatted['content'][0]['type'])->toBe('tool_result')
            ->and($formatted['content'][0]['tool_use_id'])->toBe('call_123')
            ->and($formatted['content'][0]['content'])->toBe('Sunny, 25°C');
    });

    // ========== formatMessages Tests ==========

    it('filters out system and developer messages', function () {
        $messages = [
            Message::system('System prompt'),
            Message::developer('Developer note'),
            Message::user('Hello'),
            Message::assistant('Hi!'),
        ];

        $formatted = $this->formatter->formatMessages($messages);

        expect($formatted)->toHaveCount(2)
            ->and($formatted[0]['role'])->toBe('user')
            ->and($formatted[1]['role'])->toBe('assistant');
    });

    // ========== formatTools Tests ==========

    it('formats tools with input_schema', function () {
        $tool = Tool::create('search', 'Search for information')
            ->addProperty('query', 'string', 'Search query')
            ->setRequired('query');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted)->toHaveCount(1)
            ->and($formatted[0]['name'])->toBe('search')
            ->and($formatted[0]['description'])->toBe('Search for information')
            ->and($formatted[0]['input_schema']['type'])->toBe('object')
            ->and($formatted[0]['input_schema']['properties'])->toHaveKey('query')
            ->and($formatted[0]['input_schema']['required'])->toBe(['query']);
    });

    it('includes empty input_schema when tool has no properties', function () {
        $tool = Tool::create('get_time', 'Get current time');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted[0]['input_schema'])->toBeArray()
            ->and($formatted[0]['input_schema']['type'])->toBe('object');
    });

    // ========== extractSystemInstruction Tests ==========

    it('extracts system instruction from messages', function () {
        $messages = [
            Message::system('You are a helpful assistant.'),
            Message::user('Hello'),
        ];

        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        expect($systemInstruction)->toBe('You are a helpful assistant.');
    });

    it('combines multiple system messages', function () {
        $messages = [
            Message::system('You are helpful.'),
            Message::developer('Be concise.'),
            Message::user('Hello'),
        ];

        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        expect($systemInstruction)->toBe("You are helpful.\nBe concise.");
    });

    it('returns null when no system messages', function () {
        $messages = [
            Message::user('Hello'),
            Message::assistant('Hi!'),
        ];

        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        expect($systemInstruction)->toBeNull();
    });

    // ========== extractUsage Tests ==========

    it('extracts usage with Claude field names', function () {
        $response = [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ];

        $usage = $this->formatter->extractUsage($response);

        expect($usage['prompt_tokens'])->toBe(100)
            ->and($usage['completion_tokens'])->toBe(50)
            ->and($usage['total_tokens'])->toBe(150);
    });

    // ========== extractToolCalls Tests ==========

    it('extracts tool calls from Claude response', function () {
        $response = [
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_123',
                    'name' => 'calculator',
                    'input' => ['expression' => '2+2'],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0])->toBeInstanceOf(ToolCall::class)
            ->and($toolCalls[0]->getId())->toBe('toolu_123')
            ->and($toolCalls[0]->getToolName())->toBe('calculator')
            ->and($toolCalls[0]->getArguments())->toBe('{"expression":"2+2"}');
    });

    it('extracts multiple tool calls', function () {
        $response = [
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'tool_a',
                    'input' => [],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_2',
                    'name' => 'tool_b',
                    'input' => [],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(2);
    });

    // ========== extractContent Tests ==========

    it('extracts text content from Claude response', function () {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello there!'],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('Hello there!');
    });

    it('finds text content among other blocks', function () {
        $response = [
            'content' => [
                ['type' => 'tool_use', 'id' => 'x', 'name' => 'y', 'input' => []],
                ['type' => 'text', 'text' => 'Here is the result.'],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('Here is the result.');
    });

    // ========== extractFinishReason Tests ==========

    it('normalizes end_turn to stop', function () {
        $response = ['stop_reason' => 'end_turn'];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('stop');
    });

    it('normalizes tool_use to tool_calls', function () {
        $response = ['stop_reason' => 'tool_use'];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('tool_calls');
    });

    it('normalizes max_tokens to length', function () {
        $response = ['stop_reason' => 'max_tokens'];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('length');
    });

    // ========== hasToolCalls Tests ==========

    it('detects tool calls in response', function () {
        $response = [
            'content' => [
                ['type' => 'tool_use', 'id' => 'x', 'name' => 'y', 'input' => []],
            ],
        ];

        expect($this->formatter->hasToolCalls($response))->toBeTrue();
    });

    it('returns false when no tool calls', function () {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello'],
            ],
        ];

        expect($this->formatter->hasToolCalls($response))->toBeFalse();
    });
});
