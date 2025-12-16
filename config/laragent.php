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
     * Default chat history storage drivers to use in Agents
     */
    'default_history_storage' => [
        \LarAgent\Context\Drivers\CacheStorage::class, // Primary
        \LarAgent\Context\Drivers\FileStorage::class,
    ],

    /**
     * Default storage drivers for context to use in Agents
     */
    'default_storage' => [
        \LarAgent\Context\Drivers\CacheStorage::class, // Primary
    ],

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
            'default_truncation_threshold' => 50000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
        ],

        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\GeminiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-2.0-flash-latest',
        ],

        'gemini_native' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'driver' => \LarAgent\Drivers\Gemini\GeminiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-2.0-flash-latest',
        ],

        'groq' => [
            'label' => 'groq',
            'api_key' => env('GROQ_API_KEY'),
            'driver' => \LarAgent\Drivers\Groq\GroqDriver::class,
            'default_truncation_threshold' => 131072,
            'default_max_completion_tokens' => 131072,
            'default_temperature' => 1,
        ],

        'claude' => [
            'label' => 'claude',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-3-7-sonnet-latest',
            'driver' => \LarAgent\Drivers\Anthropic\ClaudeDriver::class,
            'default_truncation_threshold' => 200000,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 1,
        ],

        'openrouter' => [
            'label' => 'openrouter',
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => 'openai/gpt-oss-20b:free',
            'driver' => \LarAgent\Drivers\OpenAi\OpenRouter::class,
            'default_truncation_threshold' => 200000,
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
            'default_truncation_threshold' => 131072,
            'default_max_completion_tokens' => 131072,
            'default_temperature' => 0.8,
        ],
    ],

    /**
     * Fallback provider to use when any provider fails.
     */
    'fallback_provider' => null,

    'mcp_tool_caching' => [
        'enabled' => env('MCP_TOOL_CACHE_ENABLED', false),
        'ttl' => env('MCP_TOOL_CACHE_TTL', 3600),
        'store' => env('MCP_TOOL_CACHE_STORE', null),
    ],

    'mcp_servers' => [
        'github' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'token' => env('GITHUB_API_TOKEN', null),
            'headers' => [
                // Add custom headers here - these will override default headers
            ],
            // 'string' or 'int' - controls JSON-RPC id type (default: 'int')
            'id_type' => 'int',
        ],
        'mcp_server_memory' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/server-memory',
            ],
            'timeout' => 30,
            'cwd' => base_path(),
            // milliseconds - delay after process start (default: 100)
            'startup_delay' => 100,
            // milliseconds - polling interval for response (default: 20)
            'poll_interval' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how LarAgent tracks and stores token usage metrics
    |
    */

    /**
     * Enable usage tracking globally for all agents.
     * Can be overridden per-provider (in providers array) or per-agent via $trackUsage property.
     * Priority: Agent property > Provider config > Global config
     */
    'track_usage' => false,

    /**
     * Default storage drivers for usage tracking.
     * Used when agent or provider doesn't set usage_storage.
     * If not set, uses 'default_storage' configuration.
     *
     * Must be an array of driver classes (e.g., [CacheStorage::class, FileStorage::class])
     * or null to use default_storage.
     *
     * Note: Per-provider configuration can be set in the providers array
     * using 'usage_storage' key with an array of driver classes.
     */
    'default_usage_storage' => null,

    /*
    |--------------------------------------------------------------------------
    | Context Window Management
    |--------------------------------------------------------------------------
    |
    | Configure how LarAgent handles conversations that exceed the defined
    | context window limit. Various strategies are available to manage
    | long conversations while preserving important context.
    |
    */

    /**
     * IMPORTANT: About 'default_truncation_threshold'
     * -----------------------------------------
     * The 'default_truncation_threshold' setting is NOT the same as the model's official context window.
     * This is the AGENT's managed truncation threshold used for internal truncation and history management.
     *
     * It should be set significantly LOWER than the model's actual context window to:
     * - Reserve space for incoming user messages and assistant responses
     * - Account for token estimation inaccuracies
     * - Provide headroom for tool calls and structured outputs
     * - Keep token usage lower for cost efficiency
     *
     * Recommendations:
     * - Maximum: 80% of model's context window (aggressive, may cause issues with large messages)
     * - Recommended: 30-50% of model's context window (balanced approach)
     * - Conservative: 20-30% for agents with large tool outputs or structured responses
     *
     * Example for 128K context:
     * - Model context: 128,000 tokens
     * - Recommended truncation threshold: 38,000-64,000 tokens (30-50%)
     */

    /**
     * Enable truncation globally for all agents.
     * Can be overridden per-provider (in providers array) or per-agent via $enableTruncation property.
     * If enabled, agents will use truncation strategies as soon as history exceeds $truncationThreshold.
     * Priority: Agent property -> Provider config -> Global config
     */
    'enable_truncation' => false,

    /**
     * Provider to use for built-in truncation agents (ChatSummarizerAgent, ChatSymbolizerAgent).
     * These agents are used by SummarizationStrategy and SymbolizationStrategy to process messages.
     * Set to any provider name from the 'providers' array above.
     */
    'truncation_provider' => 'default',

    /**
     * Default truncation strategy class to use.
     * Can be overridden per-provider or per-agent by overriding truncationStrategy() method.
     * Available strategies:
     * - \LarAgent\Context\Truncation\SimpleTruncationStrategy (keeps last N messages)
     * - \LarAgent\Context\Truncation\SummarizationStrategy (summarizes removed messages)
     * - \LarAgent\Context\Truncation\SymbolizationStrategy (creates brief symbols for removed messages)
     */
    'default_truncation_strategy' => \LarAgent\Context\Truncation\SimpleTruncationStrategy::class,

    /**
     * Default configuration for truncation strategies.
     * Can be overridden per-provider or per-agent.
     */
    'default_truncation_config' => [
        'keep_messages' => 10,
        'preserve_system' => true,
    ],

    /**
     * Truncation buffer percentage (0.0 to 1.0).
     * Reserves this percentage of the AGENT's truncation threshold for safety margin.
     * Default: 0.2 (20% reserved, 80% available for history)
     *
     * Note: This buffer works alongside the agent's truncation threshold setting (see 'default_truncation_threshold').
     * Since the agent's truncation threshold should already be set lower than the model's limit,
     * this buffer provides additional protection against edge cases and token estimation variance.
     *
     * Examples:
     * - 0.1 (10%): Minimal buffer, use when truncation threshold is already very conservative
     * - 0.2 (20%): Balanced approach (default)
     * - 0.3 (30%): Extra safety margin for unpredictable message sizes
     */
    'truncation_buffer' => 0.2,
];
