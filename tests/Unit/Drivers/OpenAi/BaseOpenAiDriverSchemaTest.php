<?php

/**
 * Tests for BaseOpenAiDriver schema transformation for OpenAI strict mode.
 *
 * OpenAI strict mode requires:
 * 1. All properties must be in the required array
 * 2. Optional properties must have null as a valid type (using anyOf)
 * 3. additionalProperties: false on all objects
 * 4. Use anyOf instead of oneOf for union types
 */

use LarAgent\Drivers\OpenAi\OpenAiDriver;

// Create a test class that exposes protected methods
class TestableOpenAiDriver extends OpenAiDriver
{
    public function __construct()
    {
        // Skip parent constructor to avoid API key requirement
    }

    public function publicTransformSchemaForStrictMode(array $schema): array
    {
        return $this->transformSchemaForStrictMode($schema);
    }

    public function publicWrapSchemaWithNull(array $schema): array
    {
        return $this->wrapSchemaWithNull($schema);
    }

    public function publicConvertOneOfToAnyOf(array $schema): array
    {
        return $this->convertOneOfToAnyOf($schema);
    }

    public function publicMakeAllPropertiesRequired(array $schema): array
    {
        return $this->makeAllPropertiesRequired($schema);
    }

    public function publicTransformToolsForStrictMode(array $tools): array
    {
        return $this->transformToolsForStrictMode($tools);
    }
}

beforeEach(function () {
    $this->driver = new TestableOpenAiDriver;
});

describe('BaseOpenAiDriver Schema Transformation', function () {
    describe('convertOneOfToAnyOf()', function () {
        it('converts oneOf to anyOf at root level', function () {
            $schema = [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ];

            $result = $this->driver->publicConvertOneOfToAnyOf($schema);

            expect($result)->toHaveKey('anyOf');
            expect($result)->not->toHaveKey('oneOf');
            expect($result['anyOf'])->toHaveCount(2);
        });

        it('converts oneOf to anyOf in nested properties', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'unionField' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            ['type' => 'integer'],
                        ],
                    ],
                ],
            ];

            $result = $this->driver->publicConvertOneOfToAnyOf($schema);

            expect($result['properties']['unionField'])->toHaveKey('anyOf');
            expect($result['properties']['unionField'])->not->toHaveKey('oneOf');
        });

        it('converts oneOf to anyOf in array items', function () {
            $schema = [
                'type' => 'array',
                'items' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'object'],
                    ],
                ],
            ];

            $result = $this->driver->publicConvertOneOfToAnyOf($schema);

            expect($result['items'])->toHaveKey('anyOf');
            expect($result['items'])->not->toHaveKey('oneOf');
        });
    });

    describe('wrapSchemaWithNull()', function () {
        it('uses type array for simple types', function () {
            $schema = ['type' => 'string'];

            $result = $this->driver->publicWrapSchemaWithNull($schema);

            expect($result['type'])->toBe(['string', 'null']);
        });

        it('uses anyOf for enum types', function () {
            $schema = ['type' => 'string', 'enum' => ['a', 'b']];

            $result = $this->driver->publicWrapSchemaWithNull($schema);

            expect($result)->toHaveKey('anyOf');
            expect($result['anyOf'])->toHaveCount(2);
            expect($result['anyOf'][0])->toBe(['type' => 'string', 'enum' => ['a', 'b']]);
            expect($result['anyOf'][1])->toBe(['type' => 'null']);
        });

        it('uses anyOf for object types', function () {
            $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

            $result = $this->driver->publicWrapSchemaWithNull($schema);

            expect($result)->toHaveKey('anyOf');
            expect($result['anyOf'])->toHaveCount(2);
            expect($result['anyOf'][0]['type'])->toBe('object');
            expect($result['anyOf'][1])->toBe(['type' => 'null']);
        });

        it('adds null to existing anyOf', function () {
            $schema = [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ];

            $result = $this->driver->publicWrapSchemaWithNull($schema);

            expect($result['anyOf'])->toHaveCount(3);
            expect($result['anyOf'][2])->toBe(['type' => 'null']);
        });

        it('does not duplicate null in existing anyOf', function () {
            $schema = [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'null'],
                ],
            ];

            $result = $this->driver->publicWrapSchemaWithNull($schema);

            expect($result['anyOf'])->toHaveCount(2);
        });
    });

    describe('makeAllPropertiesRequired()', function () {
        it('adds all properties to required array', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
                'required' => ['name'],
            ];

            $result = $this->driver->publicMakeAllPropertiesRequired($schema);

            expect($result['required'])->toContain('name', 'age');
            expect($result['required'])->toHaveCount(2);
        });

        it('wraps originally optional properties with null', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'nickname' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ];

            $result = $this->driver->publicMakeAllPropertiesRequired($schema);

            // name was required, should stay as-is
            expect($result['properties']['name'])->toBe(['type' => 'string']);

            // nickname was optional, should have null added
            expect($result['properties']['nickname']['type'])->toBe(['string', 'null']);
        });

        it('wraps optional enum with anyOf null', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                ],
                'required' => [],
            ];

            $result = $this->driver->publicMakeAllPropertiesRequired($schema);

            expect($result['properties']['status'])->toHaveKey('anyOf');
            expect($result['properties']['status']['anyOf'])->toHaveCount(2);
            expect($result['properties']['status']['anyOf'][1])->toBe(['type' => 'null']);
        });

        it('processes nested objects recursively', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'user' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                'required' => ['user'],
            ];

            $result = $this->driver->publicMakeAllPropertiesRequired($schema);

            // Nested object should also have all properties required
            expect($result['properties']['user']['required'])->toContain('name', 'email');

            // email was optional in nested, should have null
            expect($result['properties']['user']['properties']['email']['type'])->toBe(['string', 'null']);
        });
    });

    describe('transformSchemaForStrictMode()', function () {
        it('applies all transformations together', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'status' => [
                        'oneOf' => [
                            ['type' => 'string', 'enum' => ['active', 'inactive']],
                            ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['id'],
            ];

            $result = $this->driver->publicTransformSchemaForStrictMode($schema);

            // All properties should be required
            expect($result['required'])->toContain('id', 'name', 'status');

            // oneOf should be converted to anyOf
            expect($result['properties']['status'])->toHaveKey('anyOf');
            expect($result['properties']['status'])->not->toHaveKey('oneOf');

            // Optional properties should have null
            expect($result['properties']['name']['type'])->toBe(['string', 'null']);

            // additionalProperties should be false
            expect($result['additionalProperties'])->toBeFalse();
        });

        it('handles complex nested schema', function () {
            $schema = [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'value' => [
                                    'oneOf' => [
                                        ['type' => 'string'],
                                        ['type' => 'integer'],
                                    ],
                                ],
                            ],
                            'required' => [],
                        ],
                    ],
                ],
                'required' => ['items'],
            ];

            $result = $this->driver->publicTransformSchemaForStrictMode($schema);

            // Nested array item schema should be transformed
            $itemSchema = $result['properties']['items']['items'];

            expect($itemSchema['required'])->toContain('value');
            expect($itemSchema['properties']['value'])->toHaveKey('anyOf');
            expect($itemSchema['additionalProperties'])->toBeFalse();
        });
    });

    describe('transformToolsForStrictMode()', function () {
        it('transforms tool parameter schemas', function () {
            $tools = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get weather',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'city' => ['type' => 'string'],
                                'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']],
                            ],
                            'required' => ['city'],
                        ],
                    ],
                ],
            ];

            $result = $this->driver->publicTransformToolsForStrictMode($tools);

            $params = $result[0]['function']['parameters'];

            // All properties should be required
            expect($params['required'])->toContain('city', 'unit');

            // Optional unit should have null
            expect($params['properties']['unit'])->toHaveKey('anyOf');

            // Should have strict flag
            expect($result[0]['function']['strict'])->toBeTrue();

            // Should have additionalProperties: false
            expect($params['additionalProperties'])->toBeFalse();
        });
    });
});
