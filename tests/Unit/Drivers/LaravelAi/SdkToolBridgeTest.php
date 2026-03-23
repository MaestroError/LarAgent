<?php

use LarAgent\Drivers\LaravelAi\SdkToolBridge;
use LarAgent\Tool;

// SdkToolBridge requires the SDK classes to exist, so all tests are guarded
beforeEach(function () {
    if (! interface_exists(\Laravel\Ai\Contracts\Tool::class)) {
        $this->markTestSkipped('laravel/ai SDK is not installed');
    }
});

describe('SdkToolBridge', function () {

    it('returns the LarAgent tool name', function () {
        $tool = Tool::create('weather_check', 'Check the weather');
        $bridge = new SdkToolBridge($tool);

        expect($bridge->name())->toBe('weather_check');
    });

    it('returns the LarAgent tool description', function () {
        $tool = Tool::create('weather_check', 'Check the current weather conditions');
        $bridge = new SdkToolBridge($tool);

        expect($bridge->description())->toBe('Check the current weather conditions');
    });

    it('exposes the underlying LarAgent tool', function () {
        $tool = Tool::create('test_tool', 'A test tool');
        $bridge = new SdkToolBridge($tool);

        expect($bridge->getLarAgentTool())->toBe($tool);
    });

    it('creates bridges from an array of LarAgent tools', function () {
        $tools = [
            Tool::create('tool_a', 'Tool A'),
            Tool::create('tool_b', 'Tool B'),
        ];

        $bridges = SdkToolBridge::fromLarAgentTools($tools);

        expect($bridges)->toHaveCount(2);
        expect($bridges[0])->toBeInstanceOf(SdkToolBridge::class);
        expect($bridges[1])->toBeInstanceOf(SdkToolBridge::class);
        expect($bridges[0]->name())->toBe('tool_a');
        expect($bridges[1]->name())->toBe('tool_b');
    });
});
