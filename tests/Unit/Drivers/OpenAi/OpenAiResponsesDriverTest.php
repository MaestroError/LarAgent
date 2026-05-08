<?php

use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
use LarAgent\Drivers\OpenAi\OpenAiResponsesMessageFormatter;
use LarAgent\Messages\DeveloperMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\UserMessage;

// Testable subclass to expose protected methods
class TestableOpenAiResponsesDriver extends OpenAiResponsesDriver
{
    public function __construct()
    {
        // Skip parent constructor to avoid API key requirement
    }

    public function publicPreparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        return $this->preparePayload($messages, $overrideSettings);
    }

    public function publicTransformToolsForResponsesApi(array $tools): array
    {
        return $this->transformToolsForResponsesApi($tools);
    }

    public function publicExtractInstructions(array $formattedInput, ?string &$instructions): array
    {
        return $this->extractInstructions($formattedInput, $instructions);
    }

    public function getPublicFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    // Expose setDriverConfig for testing
    public function setTestConfig(DriverConfig $config): void
    {
        $this->driverConfig = $config;
    }

    // Initialize formatter for testing
    public function initForTest(): void
    {
        $this->formatter = $this->createFormatter();
        $this->driverConfig = DriverConfig::wrap([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'maxCompletionTokens' => 1000,
        ]);
    }
}

beforeEach(function () {
    $this->driver = new TestableOpenAiResponsesDriver;
    $this->driver->initForTest();
});

describe('OpenAiResponsesDriver', function () {
    describe('createFormatter()', function () {
        it('returns an OpenAiResponsesMessageFormatter', function () {
            expect($this->driver->getPublicFormatter())->toBeInstanceOf(OpenAiResponsesMessageFormatter::class);
        });
    });

    describe('preparePayload()', function () {
        it('uses input instead of messages', function () {
            $payload = $this->driver->publicPreparePayload([]);

            expect($payload)->toHaveKey('input');
            expect($payload)->not->toHaveKey('messages');
        });

        it('uses max_output_tokens instead of max_completion_tokens', function () {
            $payload = $this->driver->publicPreparePayload([]);

            expect($payload)->toHaveKey('max_output_tokens', 1000);
            expect($payload)->not->toHaveKey('max_completion_tokens');
        });

        it('includes model and temperature', function () {
            $payload = $this->driver->publicPreparePayload([]);

            expect($payload['model'])->toBe('gpt-4o-mini');
            expect($payload['temperature'])->toBe(0.7);
        });

        it('maps reasoning_effort to reasoning.effort', function () {
            $config = DriverConfig::wrap([
                'model' => 'gpt-4o-mini',
                'reasoning_effort' => 'high',
            ]);
            $this->driver->setTestConfig($config);

            $payload = $this->driver->publicPreparePayload([]);

            expect($payload)->toHaveKey('reasoning');
            expect($payload['reasoning'])->toBe(['effort' => 'high']);
            // reasoning_effort should not appear as a top-level key
            expect($payload)->not->toHaveKey('reasoning_effort');
        });

        it('includes reasoning.encrypted_content when reasoning is enabled', function () {
            $config = DriverConfig::wrap([
                'model' => 'o3',
                'reasoning_effort' => 'medium',
            ]);
            $this->driver->setTestConfig($config);

            $payload = $this->driver->publicPreparePayload([]);

            expect($payload)->toHaveKey('include');
            expect($payload['include'])->toContain('reasoning.encrypted_content');
        });

        it('passes through other extras', function () {
            $config = DriverConfig::wrap([
                'model' => 'gpt-4o-mini',
                'store' => true,
            ]);
            $this->driver->setTestConfig($config);

            $payload = $this->driver->publicPreparePayload([]);

            expect($payload)->toHaveKey('store', true);
        });

        it('extracts system message into instructions parameter', function () {
            $messages = [
                new SystemMessage('You are a helpful assistant.'),
                new UserMessage('Hello'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->toHaveKey('instructions', 'You are a helpful assistant.');
            // System message should be removed from input
            foreach ($payload['input'] as $item) {
                if (($item['type'] ?? '') === 'message') {
                    expect($item['role'])->not->toBe('system');
                }
            }
        });

        it('extracts developer message into instructions parameter', function () {
            $messages = [
                new DeveloperMessage('Be concise.'),
                new UserMessage('Hello'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->toHaveKey('instructions', 'Be concise.');
        });

        it('does not set instructions when no system/developer message', function () {
            $messages = [
                new UserMessage('Hello'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->not->toHaveKey('instructions');
        });
    });

    describe('transformToolsForResponsesApi()', function () {
        it('applies strict mode to flat tool format', function () {
            $tools = [
                [
                    'type' => 'function',
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string'],
                        ],
                        'required' => ['city'],
                    ],
                ],
            ];

            $result = $this->driver->publicTransformToolsForResponsesApi($tools);

            expect($result[0])->toHaveKey('strict', true);
            expect($result[0]['parameters'])->toHaveKey('additionalProperties', false);
            // Parameters should be directly on the tool, not nested under 'function'
            expect($result[0])->not->toHaveKey('function');
            expect($result[0])->toHaveKey('parameters');
        });

        it('does not wrap parameters under function key', function () {
            $tools = [
                [
                    'type' => 'function',
                    'name' => 'test_tool',
                    'description' => 'Test',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'arg' => ['type' => 'string'],
                        ],
                        'required' => ['arg'],
                    ],
                ],
            ];

            $result = $this->driver->publicTransformToolsForResponsesApi($tools);

            expect($result[0])->toHaveKey('name', 'test_tool');
            expect($result[0])->toHaveKey('parameters');
            expect($result[0])->not->toHaveKey('function');
        });
    });
});
