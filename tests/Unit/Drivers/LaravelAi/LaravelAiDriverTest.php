<?php

use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\LaravelAi\ConfigBridge;
use LarAgent\Drivers\LaravelAi\LaravelAiDriver;

describe('LaravelAiDriver', function () {

    it('throws RuntimeException when SDK is not available', function () {
        // The SDK is not installed in the test environment
        if (ConfigBridge::isSdkAvailable()) {
            $this->markTestSkipped('SDK is available, cannot test unavailable state');
        }

        expect(fn () => new LaravelAiDriver([
            'sdk_provider' => 'openai',
        ]))->toThrow(\RuntimeException::class, 'laravel/ai package is required');
    });

    it('accepts DriverConfig in constructor', function () {
        if (ConfigBridge::isSdkAvailable()) {
            $this->markTestSkipped('SDK is available, cannot test unavailable state');
        }

        $config = DriverConfig::fromArray([
            'model' => 'gpt-4',
            'sdk_provider' => 'openai',
        ]);

        expect(fn () => new LaravelAiDriver($config))->toThrow(\RuntimeException::class);
    });

    it('accepts array in constructor', function () {
        if (ConfigBridge::isSdkAvailable()) {
            $this->markTestSkipped('SDK is available, cannot test unavailable state');
        }

        expect(fn () => new LaravelAiDriver([
            'label' => 'openai',
        ]))->toThrow(\RuntimeException::class);
    });
});

describe('LaravelAiDriver with SDK', function () {

    beforeEach(function () {
        if (! ConfigBridge::isSdkAvailable()) {
            $this->markTestSkipped('laravel/ai SDK is not installed');
        }
    });

    it('implements HookableDriver interface', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        expect($driver)->toBeInstanceOf(\LarAgent\Core\Contracts\HookableDriver::class);
    });

    it('implements LlmDriver interface', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        expect($driver)->toBeInstanceOf(\LarAgent\Core\Contracts\LlmDriver::class);
    });

    it('resolves sdk_provider from config', function () {
        $driver = new LaravelAiDriver([
            'sdk_provider' => 'anthropic',
        ]);

        // The driver should be created successfully
        expect($driver)->toBeInstanceOf(LaravelAiDriver::class);
    });

    it('resolves sdk_provider from label via ConfigBridge', function () {
        $driver = new LaravelAiDriver([
            'label' => 'claude',
        ]);

        expect($driver)->toBeInstanceOf(LaravelAiDriver::class);
    });

    it('can set hook callbacks', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        $beforeCalled = false;
        $afterCalled = false;

        $result = $driver->setHookCallbacks(
            before: function () use (&$beforeCalled) {
                $beforeCalled = true;
            },
            after: function () use (&$afterCalled) {
                $afterCalled = true;
            },
        );

        // setHookCallbacks should return self for fluent API
        expect($result)->toBe($driver);
    });

    it('can register tools', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        $tool = \LarAgent\Tool::create('test_tool', 'A test tool')
            ->addProperty('input', 'string', 'Test input')
            ->setRequired('input')
            ->setCallback(fn ($input) => "Result: $input");

        $driver->registerTool($tool);

        expect($driver->getRegisteredTools())->toHaveCount(1);
        expect($driver->getTool('test_tool'))->toBe($tool);
    });

    it('returns minimal toolCallsToMessage format', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        $toolCall = new \LarAgent\ToolCall('tc_1', 'test_tool', '{"input":"test"}');
        $result = $driver->toolCallsToMessage([$toolCall]);

        expect($result)->toHaveKey('role', 'assistant');
        expect($result)->toHaveKey('tool_calls');
        expect($result['tool_calls'])->toHaveCount(1);
        expect($result['tool_calls'][0]['function']['name'])->toBe('test_tool');
    });

    it('returns minimal toolResultToMessage format', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        $toolCall = new \LarAgent\ToolCall('tc_1', 'test_tool', '{"input":"test"}');
        $result = $driver->toolResultToMessage($toolCall, 'Result text');

        expect($result)->toHaveKey('role', 'tool');
        expect($result)->toHaveKey('tool_call_id', 'tc_1');
        expect($result)->toHaveKey('content', 'Result text');
    });

    it('json encodes non-string tool results', function () {
        $driver = new LaravelAiDriver(['sdk_provider' => 'openai']);

        $toolCall = new \LarAgent\ToolCall('tc_1', 'test_tool', '{"input":"test"}');
        $result = $driver->toolResultToMessage($toolCall, ['key' => 'value']);

        expect($result['content'])->toBe('{"key":"value"}');
    });
});
