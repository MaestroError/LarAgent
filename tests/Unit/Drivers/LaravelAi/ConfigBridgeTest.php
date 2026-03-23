<?php

use LarAgent\Drivers\LaravelAi\ConfigBridge;

describe('ConfigBridge', function () {

    describe('toLabEnum', function () {
        it('maps openai label to openai', function () {
            expect(ConfigBridge::toLabEnum('openai'))->toBe('openai');
        });

        it('maps claude label to anthropic', function () {
            expect(ConfigBridge::toLabEnum('claude'))->toBe('anthropic');
        });

        it('maps anthropic label to anthropic', function () {
            expect(ConfigBridge::toLabEnum('anthropic'))->toBe('anthropic');
        });

        it('maps gemini label to gemini', function () {
            expect(ConfigBridge::toLabEnum('gemini'))->toBe('gemini');
        });

        it('maps google label to gemini', function () {
            expect(ConfigBridge::toLabEnum('google'))->toBe('gemini');
        });

        it('maps groq label to groq', function () {
            expect(ConfigBridge::toLabEnum('groq'))->toBe('groq');
        });

        it('maps ollama label to ollama', function () {
            expect(ConfigBridge::toLabEnum('ollama'))->toBe('ollama');
        });

        it('maps openrouter label to openrouter', function () {
            expect(ConfigBridge::toLabEnum('openrouter'))->toBe('openrouter');
        });

        it('maps azure label to azure', function () {
            expect(ConfigBridge::toLabEnum('azure'))->toBe('azure');
        });

        it('maps xai label to xai', function () {
            expect(ConfigBridge::toLabEnum('xai'))->toBe('xai');
        });

        it('maps deepseek label to deepseek', function () {
            expect(ConfigBridge::toLabEnum('deepseek'))->toBe('deepseek');
        });

        it('maps mistral label to mistral', function () {
            expect(ConfigBridge::toLabEnum('mistral'))->toBe('mistral');
        });

        it('maps cohere label to cohere', function () {
            expect(ConfigBridge::toLabEnum('cohere'))->toBe('cohere');
        });

        it('is case insensitive', function () {
            expect(ConfigBridge::toLabEnum('OpenAI'))->toBe('openai');
            expect(ConfigBridge::toLabEnum('CLAUDE'))->toBe('anthropic');
            expect(ConfigBridge::toLabEnum('Gemini'))->toBe('gemini');
        });

        it('returns unknown labels as-is', function () {
            expect(ConfigBridge::toLabEnum('custom-provider'))->toBe('custom-provider');
        });
    });

    describe('isSdkAvailable', function () {
        it('detects SDK availability correctly', function () {
            // Result depends on whether laravel/ai is installed in this environment
            $expected = class_exists(\Laravel\Ai\Ai::class) && function_exists('Laravel\\Ai\\agent');
            expect(ConfigBridge::isSdkAvailable())->toBe($expected);
        });
    });
});
