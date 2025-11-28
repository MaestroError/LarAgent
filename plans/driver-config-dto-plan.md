# Driver Configuration DTO Plan

## Overview

This document outlines a plan to replace array-based configuration with structured DTOs (Data Transfer Objects) for LLM driver settings, request options, and response handling. This eliminates magic string keys and provides type safety, IDE autocompletion, and validation.

## Current State Analysis

### Problems with Current Architecture

1. **Magic String Keys Everywhere**

    ```php
    // Provider config
    $settings['api_key']
    $settings['default_context_window']
    $settings['default_max_completion_tokens']

    // Request options
    $options['model']
    $options['temperature']
    $options['max_tokens']
    $options['tool_choice']

    // Response handling
    $response['choices'][0]['message']['content']
    $response['usage']['prompt_tokens']
    ```

2. **Inconsistent Key Names Across Drivers**
    - OpenAI: `max_tokens`
    - Claude: `max_completion_tokens`
    - Gemini: `maxOutputTokens`
3. **No Validation Until Runtime**

    - Invalid keys silently ignored
    - Type mismatches cause cryptic errors
    - No IDE support for valid options

4. **Configuration Spread Across Files**
    - `config/laragent.php` - Provider settings
    - Agent classes - Model-specific settings
    - Runtime - Request options

### Current Configuration Flow

```
config/laragent.php → Provider array → Driver constructor → $this->settings array
                                                                    ↓
Agent properties → buildConfig() → $options array → preparePayload() → API call
```

---

## Proposed Architecture

### Core Principle

> **Every configuration point uses a typed DTO. Drivers transform DTOs to API-specific format.**

### DTO Hierarchy

```
ProviderConfig (static, from config file)
    ├── api_key
    ├── api_url
    ├── model
    ├── context_window
    └── defaults: RequestConfig

RequestConfig (per-request, from agent/runtime)
    ├── model
    ├── temperature
    ├── max_tokens
    ├── top_p
    ├── tool_choice
    └── extras: array (driver-specific)

ResponseData (from API response)
    ├── message: MessageInterface
    ├── usage: UsageData
    ├── finish_reason: string
    └── raw: array (original response)
```

---

## Implementation Plan

### Phase 1: Create Core DTOs

#### ProviderConfig

```php
// src/Core/DTOs/ProviderConfig.php
namespace LarAgent\Core\DTOs;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ProviderConfig extends DataModel
{
    #[Desc('Provider label/name')]
    public string $label;

    #[Desc('API key for authentication')]
    public ?string $api_key = null;

    #[Desc('Base URL for API calls')]
    public ?string $api_url = null;

    #[Desc('Default model to use')]
    public ?string $model = null;

    #[Desc('Driver class name')]
    public string $driver;

    #[Desc('Context window size in tokens')]
    public int $context_window = 128000;

    #[Desc('Maximum completion tokens')]
    public int $max_completion_tokens = 4096;

    #[Desc('Default temperature')]
    public float $temperature = 1.0;

    /**
     * Create from legacy array format (for backward compatibility)
     */
    public static function fromLegacyArray(array $config): static
    {
        return new static(
            label: $config['label'] ?? 'unknown',
            api_key: $config['api_key'] ?? null,
            api_url: $config['api_url'] ?? null,
            model: $config['model'] ?? null,
            driver: $config['driver'],
            context_window: $config['default_context_window'] ?? 128000,
            max_completion_tokens: $config['default_max_completion_tokens'] ?? 4096,
            temperature: $config['default_temperature'] ?? 1.0,
        );
    }
}
```

#### RequestConfig

```php
// src/Core/DTOs/RequestConfig.php
namespace LarAgent\Core\DTOs;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class RequestConfig extends DataModel
{
    #[Desc('Model identifier')]
    public ?string $model = null;

    #[Desc('Sampling temperature (0.0 to 2.0)')]
    public ?float $temperature = null;

    #[Desc('Maximum tokens to generate')]
    public ?int $max_tokens = null;

    #[Desc('Top-p sampling parameter')]
    public ?float $top_p = null;

    #[Desc('Top-k sampling parameter (Gemini)')]
    public ?int $top_k = null;

    #[Desc('Number of completions to generate')]
    public ?int $n = null;

    #[Desc('Stop sequences')]
    public ?array $stop = null;

    #[Desc('Tool choice configuration')]
    public null|string|ToolChoice $tool_choice = null;

    #[Desc('Whether to stream the response')]
    public bool $stream = false;

    #[Desc('Driver-specific extra options')]
    public array $extras = [];

    /**
     * Merge with provider defaults
     */
    public function withDefaults(ProviderConfig $provider): static
    {
        $merged = clone $this;
        $merged->model ??= $provider->model;
        $merged->temperature ??= $provider->temperature;
        $merged->max_tokens ??= $provider->max_completion_tokens;
        return $merged;
    }

    /**
     * Create from legacy array format
     */
    public static function fromLegacyArray(array $options): static
    {
        $config = new static();
        $config->fill($options);

        // Handle extras (unknown keys)
        $knownKeys = ['model', 'temperature', 'max_tokens', 'top_p', 'top_k', 'n', 'stop', 'tool_choice', 'stream'];
        foreach ($options as $key => $value) {
            if (!in_array($key, $knownKeys)) {
                $config->extras[$key] = $value;
            }
        }

        return $config;
    }
}
```

#### ToolChoice

```php
// src/Core/DTOs/ToolChoice.php
namespace LarAgent\Core\DTOs;

use LarAgent\Core\Abstractions\DataModel;

class ToolChoice extends DataModel
{
    public string $type; // 'auto', 'none', 'required', 'function'
    public ?string $function_name = null;

    public static function auto(): static
    {
        $tc = new static();
        $tc->type = 'auto';
        return $tc;
    }

    public static function none(): static
    {
        $tc = new static();
        $tc->type = 'none';
        return $tc;
    }

    public static function required(): static
    {
        $tc = new static();
        $tc->type = 'required';
        return $tc;
    }

    public static function function(string $name): static
    {
        $tc = new static();
        $tc->type = 'function';
        $tc->function_name = $name;
        return $tc;
    }
}
```

#### UsageData

```php
// src/Core/DTOs/UsageData.php
namespace LarAgent\Core\DTOs;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class UsageData extends DataModel
{
    #[Desc('Number of tokens in the prompt')]
    public int $prompt_tokens = 0;

    #[Desc('Number of tokens in the completion')]
    public int $completion_tokens = 0;

    #[Desc('Total tokens used')]
    public int $total_tokens = 0;

    /**
     * Parse from different API formats
     */
    public static function fromApiResponse(array $usage, string $driver = 'openai'): static
    {
        return match ($driver) {
            'gemini' => new static(
                prompt_tokens: $usage['promptTokenCount'] ?? 0,
                completion_tokens: $usage['candidatesTokenCount'] ?? 0,
                total_tokens: $usage['totalTokenCount'] ?? 0,
            ),
            'claude' => new static(
                prompt_tokens: $usage['input_tokens'] ?? 0,
                completion_tokens: $usage['output_tokens'] ?? 0,
                total_tokens: ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ),
            default => new static(
                prompt_tokens: $usage['prompt_tokens'] ?? 0,
                completion_tokens: $usage['completion_tokens'] ?? 0,
                total_tokens: $usage['total_tokens'] ?? 0,
            ),
        };
    }
}
```

#### ResponseData

```php
// src/Core/DTOs/ResponseData.php
namespace LarAgent\Core\DTOs;

use LarAgent\Core\Contracts\Message as MessageInterface;

class ResponseData
{
    public function __construct(
        public MessageInterface $message,
        public UsageData $usage,
        public string $finish_reason,
        public ?string $model = null,
        public ?string $id = null,
        public array $raw = [],
    ) {}
}
```

### Phase 2: Create Driver-Specific Config Transformers

Each driver needs to transform canonical DTOs to API-specific format:

```php
// src/Core/Contracts/ConfigTransformer.php
namespace LarAgent\Core\Contracts;

use LarAgent\Core\DTOs\RequestConfig;
use LarAgent\Core\DTOs\ProviderConfig;

interface ConfigTransformer
{
    /**
     * Transform RequestConfig to driver-specific payload options
     */
    public function transformRequest(RequestConfig $config, ProviderConfig $provider): array;

    /**
     * Transform tool choice to driver-specific format
     */
    public function transformToolChoice(ToolChoice|string|null $toolChoice): mixed;
}
```

#### OpenAI Transformer

```php
// src/Drivers/OpenAi/OpenAiConfigTransformer.php
namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\Contracts\ConfigTransformer;
use LarAgent\Core\DTOs\RequestConfig;
use LarAgent\Core\DTOs\ProviderConfig;
use LarAgent\Core\DTOs\ToolChoice;

class OpenAiConfigTransformer implements ConfigTransformer
{
    public function transformRequest(RequestConfig $config, ProviderConfig $provider): array
    {
        $payload = [];

        $payload['model'] = $config->model ?? $provider->model ?? 'gpt-4o-mini';

        if ($config->temperature !== null) {
            $payload['temperature'] = $config->temperature;
        }

        if ($config->max_tokens !== null) {
            $payload['max_tokens'] = $config->max_tokens;
        }

        if ($config->top_p !== null) {
            $payload['top_p'] = $config->top_p;
        }

        if ($config->n !== null) {
            $payload['n'] = $config->n;
        }

        if ($config->stop !== null) {
            $payload['stop'] = $config->stop;
        }

        if ($config->tool_choice !== null) {
            $payload['tool_choice'] = $this->transformToolChoice($config->tool_choice);
        }

        // Add extras directly
        foreach ($config->extras as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    public function transformToolChoice(ToolChoice|string|null $toolChoice): mixed
    {
        if ($toolChoice === null) {
            return null;
        }

        if (is_string($toolChoice)) {
            return $toolChoice; // 'auto', 'none', 'required'
        }

        if ($toolChoice->type === 'function') {
            return [
                'type' => 'function',
                'function' => ['name' => $toolChoice->function_name],
            ];
        }

        return $toolChoice->type;
    }
}
```

#### Gemini Transformer

```php
// src/Drivers/Gemini/GeminiConfigTransformer.php
namespace LarAgent\Drivers\Gemini;

use LarAgent\Core\Contracts\ConfigTransformer;
use LarAgent\Core\DTOs\RequestConfig;
use LarAgent\Core\DTOs\ProviderConfig;
use LarAgent\Core\DTOs\ToolChoice;

class GeminiConfigTransformer implements ConfigTransformer
{
    public function transformRequest(RequestConfig $config, ProviderConfig $provider): array
    {
        $generationConfig = [];

        if ($config->temperature !== null) {
            $generationConfig['temperature'] = $config->temperature;
        }

        // Gemini uses different key name
        if ($config->max_tokens !== null) {
            $generationConfig['maxOutputTokens'] = $config->max_tokens;
        }

        if ($config->top_p !== null) {
            $generationConfig['topP'] = $config->top_p;
        }

        // Gemini-specific option
        if ($config->top_k !== null) {
            $generationConfig['topK'] = $config->top_k;
        }

        return [
            'model' => $config->model ?? $provider->model ?? 'gemini-1.5-flash-latest',
            'generationConfig' => $generationConfig,
        ];
    }

    public function transformToolChoice(ToolChoice|string|null $toolChoice): mixed
    {
        // Gemini has different tool config format
        if ($toolChoice === null) {
            return null;
        }

        // Map to Gemini's toolConfig format
        return match ($toolChoice instanceof ToolChoice ? $toolChoice->type : $toolChoice) {
            'none' => ['functionCallingConfig' => ['mode' => 'NONE']],
            'auto' => ['functionCallingConfig' => ['mode' => 'AUTO']],
            'required' => ['functionCallingConfig' => ['mode' => 'ANY']],
            default => null,
        };
    }
}
```

#### Claude Transformer

```php
// src/Drivers/Anthropic/ClaudeConfigTransformer.php
namespace LarAgent\Drivers\Anthropic;

use LarAgent\Core\Contracts\ConfigTransformer;
use LarAgent\Core\DTOs\RequestConfig;
use LarAgent\Core\DTOs\ProviderConfig;

class ClaudeConfigTransformer implements ConfigTransformer
{
    public function transformRequest(RequestConfig $config, ProviderConfig $provider): array
    {
        $payload = [];

        $payload['model'] = $config->model ?? $provider->model ?? 'claude-3-7-sonnet-latest';

        // Claude uses different key name
        $payload['max_tokens'] = $config->max_tokens ?? $provider->max_completion_tokens ?? 1024;

        if ($config->temperature !== null) {
            $payload['temperature'] = $config->temperature;
        }

        if ($config->top_p !== null) {
            $payload['top_p'] = $config->top_p;
        }

        if ($config->stop !== null) {
            $payload['stop_sequences'] = $config->stop;
        }

        return $payload;
    }

    public function transformToolChoice(ToolChoice|string|null $toolChoice): mixed
    {
        // Claude has its own tool_choice format
        if ($toolChoice === null) {
            return null;
        }

        if ($toolChoice instanceof ToolChoice && $toolChoice->type === 'function') {
            return ['type' => 'tool', 'name' => $toolChoice->function_name];
        }

        return match ($toolChoice instanceof ToolChoice ? $toolChoice->type : $toolChoice) {
            'auto' => ['type' => 'auto'],
            'required' => ['type' => 'any'],
            'none' => null, // Claude doesn't support 'none' - just don't send tools
            default => null,
        };
    }
}
```

### Phase 3: Update Driver Interface

```php
// src/Core/Contracts/LlmDriver.php
namespace LarAgent\Core\Contracts;

use LarAgent\Core\DTOs\RequestConfig;
use LarAgent\Core\DTOs\ResponseData;
use LarAgent\Messages\AssistantMessage;

interface LlmDriver
{
    /**
     * Send messages and receive response
     *
     * @param MessageInterface[] $messages Array of message objects
     * @param RequestConfig $config Request configuration
     */
    public function send(array $messages, RequestConfig $config): ResponseData;

    /**
     * Send messages and receive streamed response
     */
    public function sendStreamed(array $messages, RequestConfig $config, ?callable $callback = null): \Generator;

    /**
     * Get the provider configuration
     */
    public function getProviderConfig(): ProviderConfig;

    /**
     * Get the message formatter for this driver
     */
    public function getMessageFormatter(): MessageFormatter;

    /**
     * Get the config transformer for this driver
     */
    public function getConfigTransformer(): ConfigTransformer;

    // ... existing tool-related methods

    /**
     * @deprecated Use send() with RequestConfig
     */
    public function sendMessage(array $messages, array $options = []): AssistantMessage;
}
```

### Phase 4: Update Driver Implementations

```php
// src/Drivers/OpenAi/BaseOpenAiDriver.php
abstract class BaseOpenAiDriver extends LlmDriver
{
    protected ProviderConfig $providerConfig;
    protected OpenAiConfigTransformer $configTransformer;
    protected OpenAiMessageFormatter $messageFormatter;

    public function __construct(array|ProviderConfig $settings = [])
    {
        if (is_array($settings)) {
            $this->providerConfig = ProviderConfig::fromLegacyArray($settings);
        } else {
            $this->providerConfig = $settings;
        }

        $this->configTransformer = new OpenAiConfigTransformer();
        $this->messageFormatter = new OpenAiMessageFormatter();

        // Legacy compatibility
        parent::__construct($settings instanceof ProviderConfig ? $settings->toArray() : $settings);
    }

    public function send(array $messages, RequestConfig $config): ResponseData
    {
        // Merge with defaults
        $config = $config->withDefaults($this->providerConfig);

        // Transform config to API format
        $payload = $this->configTransformer->transformRequest($config, $this->providerConfig);

        // Format messages
        $payload['messages'] = $this->messageFormatter->formatMessages($messages);

        // Add tools if registered
        if (!empty($this->tools)) {
            $payload['tools'] = $this->getFormattedTools();

            if ($config->tool_choice !== null) {
                $payload['tool_choice'] = $this->configTransformer->transformToolChoice($config->tool_choice);
            }
        }

        // Add response schema if set
        if ($this->structuredOutputEnabled()) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $this->getResponseSchema(),
            ];
        }

        // Make API call
        $this->lastResponse = $response = $this->client->chat()->create($payload);

        // Parse response
        $message = $this->messageFormatter->parseResponse($response);
        $usage = UsageData::fromApiResponse($response->usage->toArray(), 'openai');

        return new ResponseData(
            message: $message,
            usage: $usage,
            finish_reason: $response->choices[0]->finishReason,
            model: $response->model,
            id: $response->id,
            raw: $response->toArray(),
        );
    }

    /**
     * @deprecated Use send() with RequestConfig
     */
    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        $config = RequestConfig::fromLegacyArray($options);
        $response = $this->send($messages, $config);
        return $response->message;
    }
}
```

### Phase 5: Update Agent to Use DTOs

```php
// src/LarAgent.php (partial)
class LarAgent
{
    protected ?RequestConfig $requestConfig = null;

    public function withConfig(RequestConfig $config): self
    {
        $this->requestConfig = $config;
        return $this;
    }

    public function temperature(float $temp): self
    {
        $this->requestConfig ??= new RequestConfig();
        $this->requestConfig->temperature = $temp;
        return $this;
    }

    public function maxTokens(int $tokens): self
    {
        $this->requestConfig ??= new RequestConfig();
        $this->requestConfig->max_tokens = $tokens;
        return $this;
    }

    public function toolChoice(ToolChoice|string $choice): self
    {
        $this->requestConfig ??= new RequestConfig();
        $this->requestConfig->tool_choice = $choice;
        return $this;
    }

    protected function buildConfig(): RequestConfig
    {
        $config = $this->requestConfig ?? new RequestConfig();

        // Apply agent-level settings
        $config->model ??= $this->model;
        $config->temperature ??= $this->temperature;
        // ...

        return $config;
    }

    protected function respond(): AssistantMessage
    {
        $config = $this->buildConfig();
        $response = $this->driver->send($this->chatHistory->getMessages(), $config);

        // Process response...
        return $response->message;
    }
}
```

---

## Migration Strategy

### Backward Compatibility

1. **Accept both array and DTO in constructors**:

    ```php
    public function __construct(array|ProviderConfig $settings = [])
    {
        if (is_array($settings)) {
            $this->providerConfig = ProviderConfig::fromLegacyArray($settings);
        }
    }
    ```

2. **Keep legacy methods as deprecated wrappers**:

    ```php
    /** @deprecated Use send() with RequestConfig */
    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        return $this->send($messages, RequestConfig::fromLegacyArray($options))->message;
    }
    ```

3. **Config file can use both formats**:

    ```php
    // Old format still works
    'default' => [
        'api_key' => env('OPENAI_API_KEY'),
        'driver' => OpenAiDriver::class,
    ],

    // New format also works
    'default' => ProviderConfig::make(
        api_key: env('OPENAI_API_KEY'),
        driver: OpenAiDriver::class,
    ),
    ```

### Breaking Changes (Future Major Version)

1. Remove `sendMessage()` in favor of `send()`
2. Remove array-based config support
3. Require `RequestConfig` for all requests

---

## File Changes Summary

| File                                                | Action | Description                         |
| --------------------------------------------------- | ------ | ----------------------------------- |
| `src/Core/DTOs/ProviderConfig.php`                  | CREATE | Provider configuration DTO          |
| `src/Core/DTOs/RequestConfig.php`                   | CREATE | Request options DTO                 |
| `src/Core/DTOs/ToolChoice.php`                      | CREATE | Tool choice configuration           |
| `src/Core/DTOs/UsageData.php`                       | CREATE | Token usage data                    |
| `src/Core/DTOs/ResponseData.php`                    | CREATE | Full response wrapper               |
| `src/Core/Contracts/ConfigTransformer.php`          | CREATE | Interface for config transformation |
| `src/Drivers/OpenAi/OpenAiConfigTransformer.php`    | CREATE | OpenAI-specific transformer         |
| `src/Drivers/Gemini/GeminiConfigTransformer.php`    | CREATE | Gemini-specific transformer         |
| `src/Drivers/Anthropic/ClaudeConfigTransformer.php` | CREATE | Claude-specific transformer         |
| `src/Drivers/Groq/GroqConfigTransformer.php`        | CREATE | Groq-specific transformer           |
| `src/Core/Contracts/LlmDriver.php`                  | MODIFY | Add new typed methods               |
| `src/Core/Abstractions/LlmDriver.php`               | MODIFY | Implement new interface             |
| `src/Drivers/OpenAi/BaseOpenAiDriver.php`           | MODIFY | Use DTOs internally                 |
| `src/Drivers/Gemini/GeminiDriver.php`               | MODIFY | Use DTOs internally                 |
| `src/Drivers/Anthropic/ClaudeDriver.php`            | MODIFY | Use DTOs internally                 |
| `src/Drivers/Groq/GroqDriver.php`                   | MODIFY | Use DTOs internally                 |
| `src/LarAgent.php`                                  | MODIFY | Add DTO-based configuration         |
| `src/Agent.php`                                     | MODIFY | Add DTO-based configuration         |

---

## Benefits Summary

1. **Type Safety**

    - IDE autocompletion for all config options
    - Compile-time errors for invalid properties
    - Clear types for all values

2. **Validation**

    - Can add validation rules to DTOs
    - Invalid configs caught early
    - Default values clearly defined

3. **Documentation**

    - DTOs serve as documentation
    - `#[Desc]` attributes describe each option
    - Schema generation for API docs

4. **Driver Isolation**

    - Each driver transforms DTOs independently
    - Easy to add driver-specific options via `extras`
    - Clear separation of concerns

5. **Testability**
    - DTOs easy to mock
    - Transformers easy to unit test
    - No magic string dependencies

---

## Relationship to Message Standardization

This plan complements the Message Standardization plan:

| Component          | Message Plan                 | Config Plan                   |
| ------------------ | ---------------------------- | ----------------------------- |
| **Data Flow**      | Messages in canonical format | Config in canonical DTOs      |
| **Transformation** | MessageFormatter per driver  | ConfigTransformer per driver  |
| **Storage**        | Messages store canonical     | Config used at runtime        |
| **Extension**      | Driver extras in messages    | Extras array in RequestConfig |

Together, these plans eliminate array-based approaches throughout the codebase.

---

## Open Questions

1. **Should ProviderConfig be immutable?**

    - Once created, should values be changeable?
    - Immutability prevents accidental modification

2. **How to handle provider-specific options?**

    - Claude's `anthropic-beta` header
    - Gemini's `safety_settings`
    - Use `extras` or typed sub-DTOs?

3. **Should RequestConfig merge or override?**

    - When combining agent config with runtime config
    - Current: runtime overrides agent
    - Alternative: deep merge

4. **Validation timing?**
    - Validate in constructor (fail fast)?
    - Validate in transformer (at use time)?
    - Both?

---

## Next Steps

1. Review and approve this plan alongside Message Standardization plan
2. Decide on implementation order (Messages first or Config first?)
3. Create DTO classes
4. Create transformers
5. Update drivers incrementally
6. Update agents
7. Write tests
8. Update documentation
