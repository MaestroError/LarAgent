<?php

// config for Maestroerror/LarAgent
return [

    /**
     * Default driver to use, binded in service provider
     * with \LarAgent\Core\Contracts\LlmDriver interface
     */
    'default_driver' => \LarAgent\Drivers\OpenAi\OpenAiCompatible::class,

    /**
     * Default chat history to use, binded in service provider
     * with \LarAgent\Core\Contracts\ChatHistory interface
     */
    'default_chat_history' => \LarAgent\History\InMemoryChatHistory::class,

    /**
     * Autodiscovery namespaces for Agent classes.
     * Used by `agent:chat` to locate agents.
     */
    'namespaces' => [
        'App\\AiAgents\\',
        'App\\Agents\\',
    ],

    /**
     * Always keep provider named 'default'
     * You can add more providers in array
     * by copying the 'default' provider
     * and changing the name and values
     *
     * You can remove any other providers
     * which your project doesn't need
     */
    'providers' => [
        'default' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
        ],

        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\GeminiDriver::class,
            'default_context_window' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
        ],

        'groq' => [
            'label' => 'groq',
            'api_key' => env('GROQ_API_KEY'),
            'driver' => \LarAgent\Drivers\Groq\GroqDriver::class,
            'default_context_window' => 131072,
            'default_max_completion_tokens' => 131072,
            'default_temperature' => 1,
        ],

        'claude' => [
            'label' => 'claude',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-3-7-sonnet-latest',
            'driver' => \LarAgent\Drivers\Anthropic\ClaudeDriver::class,
            'default_context_window' => 200000,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 1,
        ],

        'openrouter' => [
            'label' => 'openrouter',
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => 'openai/gpt-oss-20b:free',
            'driver' => \LarAgent\Drivers\OpenAi\OpenRouter::class,
            'default_context_window' => 200000,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 1,
        ],

        /**
         * Assumes you have ollama server running with default settings
         * Where URL is http://localhost:11434/v1 and no api_key
         * If you have ollama server running with custom settings
         * You can set api_key and api_url in the provider below
         */
        'ollama' => [
            'label' => 'ollama',
            'driver' => \LarAgent\Drivers\OpenAi\OllamaDriver::class,
            'default_context_window' => 131072,
            'default_max_completion_tokens' => 131072,
            'default_temperature' => 0.8,
        ],
    ],

    /**
     * Fallback provider to use when any provider fails.
     */
    'fallback_provider' => null,
];
