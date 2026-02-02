<?php

/**
 * Tests for ClaudeDriver structured output (response schema) support.
 *
 * These tests verify that ClaudeDriver correctly handles response schemas
 * according to Anthropic's structured output API specification.
 */

use LarAgent\Drivers\Anthropic\ClaudeDriver;

// Create a test class that exposes protected methods
class TestableClaudeDriver extends ClaudeDriver
{
    public function __construct()
    {
        // Skip parent constructor to avoid API key requirement
    }

    public function publicUnwrapResponseSchema(array $schema): array
    {
        return $this->unwrapResponseSchema($schema);
    }

    public function publicPreparePayload(array $messages, array $overrideSettings = []): array
    {
        // Set up minimal formatter
        $this->formatter = new \LarAgent\Drivers\Anthropic\ClaudeMessageFormatter;
        $this->driverConfig = \LarAgent\Core\DTO\DriverConfig::wrap([
            'model' => 'claude-3-7-sonnet-latest',
            'apiKey' => 'test-key',
        ]);

        return $this->preparePayload($messages, $overrideSettings);
    }

    public function publicSetResponseSchema(?array $schema): void
    {
        $this->responseSchema = $schema;
    }
}

beforeEach(function () {
    $this->driver = new TestableClaudeDriver;
});

describe('ClaudeDriver Schema Support', function () {
    describe('unwrapResponseSchema()', function () {
        it('returns raw schema unchanged', function () {
            $rawSchema = [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
                'required' => ['name', 'age'],
            ];

            $result = $this->driver->publicUnwrapResponseSchema($rawSchema);

            expect($result)->toBe($rawSchema);
        });

        it('unwraps OpenAI-style wrapped schema', function () {
            $wrappedSchema = [
                'name' => 'person_info',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'age' => ['type' => 'integer'],
                    ],
                    'required' => ['name', 'age'],
                ],
            ];

            $result = $this->driver->publicUnwrapResponseSchema($wrappedSchema);

            expect($result)->toBe($wrappedSchema['schema'])
                ->and($result)->toHaveKey('type')
                ->and($result)->toHaveKey('properties')
                ->and($result)->not->toHaveKey('name')
                ->and($result)->not->toHaveKey('strict');
        });

        it('handles schema with additionalProperties', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string'],
                ],
                'required' => ['city'],
                'additionalProperties' => false,
            ];

            $result = $this->driver->publicUnwrapResponseSchema($schema);

            expect($result)->toBe($schema)
                ->and($result['additionalProperties'])->toBe(false);
        });
    });

    describe('preparePayload() with structured output', function () {
        it('includes output_config when response schema is set', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
                'required' => ['name', 'email'],
            ];

            $this->driver->publicSetResponseSchema($schema);

            $messages = [
                \LarAgent\Message::user('Extract info'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->toHaveKey('output_config')
                ->and($payload['output_config'])->toHaveKey('format')
                ->and($payload['output_config']['format'])->toHaveKey('type')
                ->and($payload['output_config']['format']['type'])->toBe('json_schema')
                ->and($payload['output_config']['format'])->toHaveKey('schema')
                ->and($payload['output_config']['format']['schema'])->toBe($schema);
        });

        it('unwraps OpenAI-wrapped schema in output_config', function () {
            $wrappedSchema = [
                'name' => 'custom_schema',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => ['type' => 'boolean'],
                    ],
                    'required' => ['result'],
                ],
            ];

            $this->driver->publicSetResponseSchema($wrappedSchema);

            $messages = [
                \LarAgent\Message::user('Process this'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload['output_config']['format']['schema'])
                ->toBe($wrappedSchema['schema'])
                ->and($payload['output_config']['format']['schema'])->not->toHaveKey('name')
                ->and($payload['output_config']['format']['schema'])->not->toHaveKey('strict');
        });

        it('does not include output_config when no response schema', function () {
            // No schema set
            $this->driver->publicSetResponseSchema(null);

            $messages = [
                \LarAgent\Message::user('Normal request'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->not->toHaveKey('output_config');
        });

        it('includes both tools and output_config when both are present', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'answer' => ['type' => 'string'],
                ],
                'required' => ['answer'],
            ];

            $this->driver->publicSetResponseSchema($schema);

            // Register a tool
            $tool = (new \LarAgent\Tool('search', 'Search for information'))
                ->addProperty('query', 'string', 'Search query')
                ->setRequired('query')
                ->setCallback(fn ($args) => 'result');

            $this->driver->registerTool($tool);

            $messages = [
                \LarAgent\Message::user('Search and respond'),
            ];

            $payload = $this->driver->publicPreparePayload($messages);

            expect($payload)->toHaveKey('tools')
                ->and($payload)->toHaveKey('output_config')
                ->and($payload['tools'])->toBeArray()
                ->and($payload['tools'])->toHaveCount(1)
                ->and($payload['output_config']['format']['schema'])->toBe($schema);
        });
    });
});
