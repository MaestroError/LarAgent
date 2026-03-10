<?php

namespace LarAgent\Drivers\LaravelAi;

class ConfigBridge
{
    /**
     * Map LarAgent provider labels to Laravel AI SDK provider names.
     *
     * @return string The SDK provider name (matches Lab enum values)
     */
    public static function toLabEnum(string $label): string
    {
        return match (strtolower($label)) {
            'openai' => 'openai',
            'claude', 'anthropic' => 'anthropic',
            'gemini', 'google' => 'gemini',
            'groq' => 'groq',
            'ollama' => 'ollama',
            'openrouter' => 'openrouter',
            'azure' => 'azure',
            'xai' => 'xai',
            'deepseek' => 'deepseek',
            'mistral' => 'mistral',
            'cohere' => 'cohere',
            default => $label,
        };
    }

    /**
     * Check if the Laravel AI SDK is available.
     * Verifies both a core SDK class and the helper function exist.
     */
    public static function isSdkAvailable(): bool
    {
        return class_exists(\Laravel\Ai\Ai::class)
            && function_exists('Laravel\\Ai\\agent');
    }
}
