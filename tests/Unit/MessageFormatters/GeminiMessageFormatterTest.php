<?php

use LarAgent\Drivers\Gemini\GeminiMessageFormatter;
use LarAgent\Message;
use LarAgent\Tool;
use LarAgent\ToolCall;

describe('GeminiMessageFormatter', function () {
    beforeEach(function () {
        $this->formatter = new GeminiMessageFormatter;
    });

    // ========== formatMessage Tests ==========

    it('formats user message with parts array', function () {
        $message = Message::user('Hello, Gemini!');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['parts'])->toBeArray()
            ->and($formatted['parts'][0]['text'])->toBe('Hello, Gemini!');
    });

    it('formats assistant message as model role', function () {
        $message = Message::assistant('I can help you.');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'])->toBeArray()
            ->and($formatted['parts'][0]['text'])->toBe('I can help you.');
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

    it('formats tool call message with functionCall', function () {
        $toolCall = new ToolCall('call_123', 'get_weather', '{"city": "Tokyo"}');
        $message = Message::toolCall([$toolCall]);
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'])->toBeArray()
            ->and($formatted['parts'][0]['functionCall']['name'])->toBe('get_weather')
            ->and($formatted['parts'][0]['functionCall']['args'])->toBe(['city' => 'Tokyo']);
    });

    it('formats tool result message with functionResponse', function () {
        $message = Message::toolResult('Sunny, 28°C', 'call_123', 'get_weather');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('user')
            ->and($formatted['parts'])->toBeArray()
            ->and($formatted['parts'][0]['functionResponse']['name'])->toBe('get_weather')
            ->and($formatted['parts'][0]['functionResponse']['response']['content'])->toBe('Sunny, 28°C');
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
            ->and($formatted[1]['role'])->toBe('model');
    });

    // ========== formatTools Tests ==========

    it('formats tools with functionDeclarations', function () {
        $tool = Tool::create('search', 'Search for information')
            ->addProperty('query', 'string', 'Search query')
            ->setRequired('query');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted)->toHaveCount(1)
            ->and($formatted[0]['functionDeclarations'])->toBeArray()
            ->and($formatted[0]['functionDeclarations'][0]['name'])->toBe('search')
            ->and($formatted[0]['functionDeclarations'][0]['description'])->toBe('Search for information')
            ->and($formatted[0]['functionDeclarations'][0]['parameters']['type'])->toBe('object')
            ->and($formatted[0]['functionDeclarations'][0]['parameters']['properties'])->toHaveKey('query');
    });

    it('formats tool without properties', function () {
        $tool = Tool::create('get_time', 'Get current time');

        $formatted = $this->formatter->formatTools([$tool]);

        expect($formatted[0]['functionDeclarations'][0]['name'])->toBe('get_time')
            ->and($formatted[0]['functionDeclarations'][0])->not->toHaveKey('parameters');
    });

    // ========== extractSystemInstruction Tests ==========

    it('extracts system instruction in Gemini format', function () {
        $messages = [
            Message::system('You are a helpful assistant.'),
            Message::user('Hello'),
        ];

        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        expect($systemInstruction)->toBeArray()
            ->and($systemInstruction['parts'])->toBeArray()
            ->and($systemInstruction['parts'][0]['text'])->toBe('You are a helpful assistant.');
    });

    it('combines multiple system messages', function () {
        $messages = [
            Message::system('You are helpful.'),
            Message::developer('Be concise.'),
            Message::user('Hello'),
        ];

        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        expect($systemInstruction['parts'][0]['text'])->toBe("You are helpful.\nBe concise.");
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

    it('extracts usage from Gemini usageMetadata', function () {
        $response = [
            'usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 50,
                'totalTokenCount' => 150,
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

    it('extracts tool calls from Gemini response', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'calculator',
                                    'args' => ['expression' => '2+2'],
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
            ->and($toolCalls[0]->getToolName())->toBe('calculator')
            ->and($toolCalls[0]->getArguments())->toBe('{"expression":"2+2"}');
    });

    it('generates unique IDs for tool calls', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['name' => 'tool_a', 'args' => []]],
                            ['functionCall' => ['name' => 'tool_b', 'args' => []]],
                        ],
                    ],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(2)
            ->and($toolCalls[0]->getId())->not->toBe($toolCalls[1]->getId())
            ->and($toolCalls[0]->getId())->toStartWith('tool_call_');
    });

    // ========== extractContent Tests ==========

    it('extracts text content from Gemini response', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello from Gemini!'],
                        ],
                    ],
                ],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('Hello from Gemini!');
    });

    it('returns empty string when content missing', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [],
                    ],
                ],
            ],
        ];

        $content = $this->formatter->extractContent($response);

        expect($content)->toBe('');
    });

    // ========== extractFinishReason Tests ==========

    it('normalizes STOP to stop', function () {
        $response = [
            'candidates' => [
                ['finishReason' => 'STOP'],
            ],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('stop');
    });

    it('normalizes MAX_TOKENS to length', function () {
        $response = [
            'candidates' => [
                ['finishReason' => 'MAX_TOKENS'],
            ],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('length');
    });

    it('normalizes SAFETY to content_filter', function () {
        $response = [
            'candidates' => [
                ['finishReason' => 'SAFETY'],
            ],
        ];

        $reason = $this->formatter->extractFinishReason($response);

        expect($reason)->toBe('content_filter');
    });

    // ========== hasToolCalls Tests ==========

    it('detects function calls in response', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['name' => 'test', 'args' => []]],
                        ],
                    ],
                ],
            ],
        ];

        expect($this->formatter->hasToolCalls($response))->toBeTrue();
    });

    it('returns false when no function calls', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello'],
                        ],
                    ],
                ],
            ],
        ];

        expect($this->formatter->hasToolCalls($response))->toBeFalse();
    });

    // ========== Thought Signature Tests ==========

    it('extracts thought signature from function call response', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'check_flight',
                                    'args' => ['flight' => 'AA100'],
                                ],
                                'thoughtSignature' => '<Signature_A>',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0]->hasThoughtSignature())->toBeTrue()
            ->and($toolCalls[0]->getThoughtSignature())->toBe('<Signature_A>');
    });

    it('extracts thought signature only on first parallel function call', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'Paris'],
                                ],
                                'thoughtSignature' => '<Signature_Parallel>',
                            ],
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'London'],
                                ],
                                // No thoughtSignature on parallel calls
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $toolCalls = $this->formatter->extractToolCalls($response);

        expect($toolCalls)->toHaveCount(2)
            ->and($toolCalls[0]->hasThoughtSignature())->toBeTrue()
            ->and($toolCalls[0]->getThoughtSignature())->toBe('<Signature_Parallel>')
            ->and($toolCalls[1]->hasThoughtSignature())->toBeFalse();
    });

    it('extracts thought signature from text response', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => 'Let me think about this...',
                                'thoughtSignature' => '<Text_Signature>',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->formatter->extractThoughtSignature($response);

        expect($signature)->toBe('<Text_Signature>');
    });

    it('returns null when no thought signature present', function () {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello, how can I help?'],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->formatter->extractThoughtSignature($response);

        expect($signature)->toBeNull();
    });

    it('formats tool call message with thought signature', function () {
        $toolCall = new ToolCall('call_123', 'check_flight', '{"flight": "AA100"}', '<Signature_A>');
        $message = Message::toolCall([$toolCall]);
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'][0]['functionCall']['name'])->toBe('check_flight')
            ->and($formatted['parts'][0]['thoughtSignature'])->toBe('<Signature_A>');
    });

    it('formats tool call message without thought signature when not present', function () {
        $toolCall = new ToolCall('call_123', 'check_flight', '{"flight": "AA100"}');
        $message = Message::toolCall([$toolCall]);
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'][0]['functionCall']['name'])->toBe('check_flight')
            ->and($formatted['parts'][0])->not->toHaveKey('thoughtSignature');
    });

    it('formats assistant message with thought signature from extras', function () {
        $message = Message::assistant('Let me think about this...');
        $message->setExtra('thought_signature', '<Text_Signature>');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'][0]['text'])->toBe('Let me think about this...')
            ->and($formatted['parts'][0]['thoughtSignature'])->toBe('<Text_Signature>');
    });

    it('formats assistant message without thought signature when not in extras', function () {
        $message = Message::assistant('Hello!');
        $formatted = $this->formatter->formatMessage($message);

        expect($formatted['role'])->toBe('model')
            ->and($formatted['parts'][0]['text'])->toBe('Hello!')
            ->and($formatted['parts'][0])->not->toHaveKey('thoughtSignature');
    });
});
