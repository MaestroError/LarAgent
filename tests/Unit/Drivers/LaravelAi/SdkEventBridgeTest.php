<?php

use LarAgent\Drivers\LaravelAi\SdkEventBridge;

describe('SdkEventBridge', function () {

    beforeEach(function () {
        SdkEventBridge::reset();
    });

    describe('enable/disable', function () {
        it('is enabled by default', function () {
            expect(SdkEventBridge::isEnabled())->toBeTrue();
        });

        it('can be disabled', function () {
            SdkEventBridge::setEnabled(false);

            expect(SdkEventBridge::isEnabled())->toBeFalse();
        });

        it('can be re-enabled', function () {
            SdkEventBridge::setEnabled(false);
            SdkEventBridge::setEnabled(true);

            expect(SdkEventBridge::isEnabled())->toBeTrue();
        });
    });

    describe('tool guarding', function () {
        it('can guard a tool name', function () {
            SdkEventBridge::guardTool('get_weather');

            expect(SdkEventBridge::isToolGuarded('get_weather'))->toBeTrue();
        });

        it('returns false for unguarded tools', function () {
            expect(SdkEventBridge::isToolGuarded('unknown_tool'))->toBeFalse();
        });

        it('can unguard a tool', function () {
            SdkEventBridge::guardTool('get_weather');
            SdkEventBridge::unguardTool('get_weather');

            expect(SdkEventBridge::isToolGuarded('get_weather'))->toBeFalse();
        });

        it('can guard multiple tools', function () {
            SdkEventBridge::guardTool('tool_a');
            SdkEventBridge::guardTool('tool_b');

            expect(SdkEventBridge::isToolGuarded('tool_a'))->toBeTrue();
            expect(SdkEventBridge::isToolGuarded('tool_b'))->toBeTrue();
            expect(SdkEventBridge::isToolGuarded('tool_c'))->toBeFalse();
        });
    });

    describe('reset', function () {
        it('resets all state', function () {
            SdkEventBridge::setEnabled(false);
            SdkEventBridge::guardTool('my_tool');

            SdkEventBridge::reset();

            expect(SdkEventBridge::isEnabled())->toBeTrue();
            expect(SdkEventBridge::isToolGuarded('my_tool'))->toBeFalse();
        });
    });

    describe('setAgentDto', function () {
        it('accepts null without error', function () {
            SdkEventBridge::setAgentDto(null);

            // No exception should be thrown
            expect(true)->toBeTrue();
        });

        it('accepts an AgentDTO instance', function () {
            $dto = new \LarAgent\Core\DTO\AgentDTO(
                provider: 'laravel-ai',
                providerName: 'laravel-ai',
                message: 'test',
                sessionId: 'session-123',
            );

            SdkEventBridge::setAgentDto($dto);

            // No exception should be thrown
            expect(true)->toBeTrue();
        });
    });

    describe('register', function () {
        it('is idempotent - calling multiple times has no error', function () {
            // Without SDK installed, register should be a no-op
            SdkEventBridge::register();
            SdkEventBridge::register();
            SdkEventBridge::register();

            // Should not throw
            expect(true)->toBeTrue();
        });

        it('accepts optional AgentDTO', function () {
            $dto = new \LarAgent\Core\DTO\AgentDTO(
                provider: 'laravel-ai',
                providerName: 'laravel-ai',
                message: 'test',
            );

            SdkEventBridge::register($dto);

            // Should not throw
            expect(true)->toBeTrue();
        });
    });
});
