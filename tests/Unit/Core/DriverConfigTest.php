<?php

use LarAgent\Core\DTO\DriverConfig;

describe('DriverConfig', function () {
    it('creates with default null values', function () {
        $config = new DriverConfig;

        expect($config->model)->toBeNull()
            ->and($config->apiKey)->toBeNull()
            ->and($config->apiUrl)->toBeNull()
            ->and($config->temperature)->toBeNull()
            ->and($config->maxCompletionTokens)->toBeNull();
    });

    it('creates with specified values', function () {
        $config = new DriverConfig(
            model: 'gpt-4',
            apiKey: 'test-key',
            temperature: 0.7,
            maxCompletionTokens: 1000
        );

        expect($config->model)->toBe('gpt-4')
            ->and($config->apiKey)->toBe('test-key')
            ->and($config->temperature)->toBe(0.7)
            ->and($config->maxCompletionTokens)->toBe(1000);
    });

    it('creates from array with fromArray()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'apiKey' => 'test-key',
            'temperature' => 0.5,
            'topP' => 0.9,
        ]);

        expect($config->model)->toBe('gpt-4')
            ->and($config->apiKey)->toBe('test-key')
            ->and($config->temperature)->toBe(0.5)
            ->and($config->topP)->toBe(0.9);
    });

    it('stores unknown keys as extras in fromArray()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'customOption' => 'value',
            'anotherOption' => 123,
        ]);

        expect($config->model)->toBe('gpt-4')
            ->and($config->getExtra('customOption'))->toBe('value')
            ->and($config->getExtra('anotherOption'))->toBe(123);
    });

    it('wraps array into DriverConfig with wrap()', function () {
        $config = DriverConfig::wrap(['model' => 'gpt-4']);

        expect($config)->toBeInstanceOf(DriverConfig::class)
            ->and($config->model)->toBe('gpt-4');
    });

    it('returns same instance when wrapping DriverConfig', function () {
        $original = new DriverConfig(model: 'gpt-4');
        $wrapped = DriverConfig::wrap($original);

        expect($wrapped)->toBe($original);
    });

    it('converts to array with toArray()', function () {
        $config = new DriverConfig(
            model: 'gpt-4',
            temperature: 0.7,
            apiKey: 'test-key'
        );

        $array = $config->toArray();

        expect($array)->toBe([
            'model' => 'gpt-4',
            'apiKey' => 'test-key',
            'temperature' => 0.7,
        ]);
    });

    it('filters null values in toArray()', function () {
        $config = new DriverConfig(
            model: 'gpt-4',
            temperature: null,
            maxCompletionTokens: 1000
        );

        $array = $config->toArray();

        expect($array)->toHaveKeys(['model', 'maxCompletionTokens'])
            ->and($array)->not->toHaveKey('temperature')
            ->and($array)->not->toHaveKey('apiKey');
    });

    it('includes extras in toArray()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'customOption' => 'value',
        ]);

        $array = $config->toArray();

        expect($array)->toBe([
            'model' => 'gpt-4',
            'customOption' => 'value',
        ]);
    });

    it('adds extras with withExtra()', function () {
        $config = new DriverConfig(model: 'gpt-4');
        $withExtra = $config->withExtra(['customOption' => 'value']);

        // Original unchanged
        expect($config->getExtra('customOption'))->toBeNull();
        // New instance has extra
        expect($withExtra->getExtra('customOption'))->toBe('value')
            ->and($withExtra->model)->toBe('gpt-4');
    });

    it('merges extras in withExtra()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'option1' => 'value1',
        ]);

        $withExtra = $config->withExtra(['option2' => 'value2']);

        expect($withExtra->getExtra('option1'))->toBe('value1')
            ->and($withExtra->getExtra('option2'))->toBe('value2');
    });

    it('gets all extras with getExtras()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'custom1' => 'a',
            'custom2' => 'b',
        ]);

        expect($config->getExtras())->toBe([
            'custom1' => 'a',
            'custom2' => 'b',
        ]);
    });

    it('sets known property with set()', function () {
        $config = new DriverConfig;
        $config->set('model', 'gpt-4');
        $config->set('temperature', 0.5);

        expect($config->model)->toBe('gpt-4')
            ->and($config->temperature)->toBe(0.5);
    });

    it('sets unknown property as extra with set()', function () {
        $config = new DriverConfig;
        $config->set('customOption', 'value');

        expect($config->getExtra('customOption'))->toBe('value');
    });

    it('returns self from set() for chaining', function () {
        $config = new DriverConfig;
        $result = $config->set('model', 'gpt-4');

        expect($result)->toBe($config);
    });

    it('gets known property with get()', function () {
        $config = new DriverConfig(model: 'gpt-4', temperature: 0.7);

        expect($config->get('model'))->toBe('gpt-4')
            ->and($config->get('temperature'))->toBe(0.7);
    });

    it('gets extra property with get()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'customOption' => 'value',
        ]);

        expect($config->get('customOption'))->toBe('value');
    });

    it('returns default value from get() when property is null', function () {
        $config = new DriverConfig;

        expect($config->get('model', 'default-model'))->toBe('default-model')
            ->and($config->get('customOption', 'default'))->toBe('default');
    });

    it('checks if property exists with has()', function () {
        $config = new DriverConfig(model: 'gpt-4');

        expect($config->has('model'))->toBeTrue()
            ->and($config->has('temperature'))->toBeFalse();
    });

    it('checks if extra property exists with has()', function () {
        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'customOption' => 'value',
        ]);

        expect($config->has('customOption'))->toBeTrue()
            ->and($config->has('nonExistent'))->toBeFalse();
    });

    it('merges two configs with merge()', function () {
        $base = new DriverConfig(
            model: 'gpt-4',
            temperature: 0.7,
            maxCompletionTokens: 1000
        );

        $override = new DriverConfig(
            temperature: 0.9,
            topP: 0.95
        );

        $merged = $base->merge($override);

        expect($merged->model)->toBe('gpt-4') // From base
            ->and($merged->temperature)->toBe(0.9) // Overridden
            ->and($merged->maxCompletionTokens)->toBe(1000) // From base
            ->and($merged->topP)->toBe(0.95); // From override
    });

    it('merges extras when merging configs', function () {
        $base = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'extra1' => 'a',
        ]);

        $override = DriverConfig::fromArray([
            'extra2' => 'b',
        ]);

        $merged = $base->merge($override);

        expect($merged->getExtra('extra1'))->toBe('a')
            ->and($merged->getExtra('extra2'))->toBe('b');
    });

    it('override config takes precedence for non-null values in merge()', function () {
        $base = new DriverConfig(
            model: 'gpt-3.5-turbo',
            apiKey: 'base-key'
        );

        $override = new DriverConfig(
            model: 'gpt-4',
            apiKey: null // null doesn't override
        );

        $merged = $base->merge($override);

        expect($merged->model)->toBe('gpt-4')
            ->and($merged->apiKey)->toBe('base-key');
    });

    it('handles toolChoice as string', function () {
        $config = new DriverConfig(toolChoice: 'auto');

        expect($config->toolChoice)->toBe('auto')
            ->and($config->toArray()['toolChoice'])->toBe('auto');
    });

    it('handles toolChoice as array', function () {
        $toolChoice = [
            'type' => 'function',
            'function' => ['name' => 'my_tool'],
        ];

        $config = new DriverConfig(toolChoice: $toolChoice);

        expect($config->toolChoice)->toBe($toolChoice)
            ->and($config->toArray()['toolChoice'])->toBe($toolChoice);
    });

    it('handles all known properties', function () {
        $config = new DriverConfig(
            model: 'gpt-4',
            apiKey: 'key',
            apiUrl: 'https://api.example.com',
            maxCompletionTokens: 1000,
            temperature: 0.7,
            n: 2,
            topP: 0.9,
            frequencyPenalty: 0.5,
            presencePenalty: 0.5,
            parallelToolCalls: true,
            toolChoice: 'auto',
            modalities: ['text', 'audio'],
            audio: ['voice' => 'alloy'],
        );

        $array = $config->toArray();

        expect($array)->toBe([
            'model' => 'gpt-4',
            'apiKey' => 'key',
            'apiUrl' => 'https://api.example.com',
            'maxCompletionTokens' => 1000,
            'temperature' => 0.7,
            'n' => 2,
            'topP' => 0.9,
            'frequencyPenalty' => 0.5,
            'presencePenalty' => 0.5,
            'parallelToolCalls' => true,
            'toolChoice' => 'auto',
            'modalities' => ['text', 'audio'],
            'audio' => ['voice' => 'alloy'],
        ]);
    });
});
