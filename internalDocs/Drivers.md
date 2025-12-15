# LLM Drivers

LarAgent supports multiple LLM providers through a driver system. Each driver handles the specifics of communicating with a particular API while providing a consistent interface to the agent.

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                      Agent                          │
└──────────────────────┬──────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────┐
│              LlmDriver (Abstract)                   │
│  - registerTool()                                   │
│  - setResponseSchema()                              │
│  - formatToolForPayload()                           │
│  - toolResultToMessage()                            │
│  - toolCallsToMessage()                             │
└──────────────────────┬──────────────────────────────┘
                       │
       ┌───────────────┼───────────────┬──────────────┐
       ▼               ▼               ▼              ▼
┌─────────────┐ ┌─────────────┐ ┌───────────┐ ┌─────────────┐
│ OpenAiDriver│ │ ClaudeDriver│ │GeminiDriver│ │  GroqDriver │
└─────────────┘ └─────────────┘ └───────────┘ └─────────────┘
```

## Built-in Drivers

### OpenAI Driver (Default)

```php
// config/laragent.php
'providers' => [
    'default' => [
        'label' => 'openai',
        'api_key' => env('OPENAI_API_KEY'),
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'default_context_window' => 128000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 1,
    ],
],
```

**Supported Features:**
- ✅ Chat completions
- ✅ Streaming
- ✅ Tool/function calling
- ✅ Structured output (JSON schema)
- ✅ Vision (images)
- ✅ Audio input/output
- ✅ Multiple choices (n parameter)
- ✅ Parallel tool calls

### Claude Driver (Anthropic)

```php
'providers' => [
    'claude' => [
        'label' => 'claude',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-7-sonnet-latest',
        'driver' => \LarAgent\Drivers\Anthropic\ClaudeDriver::class,
        'default_context_window' => 200000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 1,
    ],
],
```

**Supported Features:**
- ✅ Chat completions
- ✅ Streaming
- ✅ Tool/function calling
- ❌ Structured output (JSON schema) - Not supported by Anthropic
- ✅ Vision (images)
- ❌ Audio

**Usage Note:** For structured output with Claude, instruct the model to return JSON in the prompt or use tools.

### Gemini Driver (Native)

```php
'providers' => [
    'gemini_native' => [
        'label' => 'gemini',
        'api_key' => env('GEMINI_API_KEY'),
        'driver' => \LarAgent\Drivers\Gemini\GeminiDriver::class,
        'default_context_window' => 1000000,
        'default_max_completion_tokens' => 10000,
        'default_temperature' => 1,
        'model' => 'gemini-2.0-flash-latest',
    ],
],
```

**Supported Features:**
- ✅ Chat completions
- ✅ Streaming
- ✅ Tool/function calling
- ✅ Structured output
- ✅ Vision

### Gemini Driver (OpenAI-Compatible)

```php
'providers' => [
    'gemini' => [
        'label' => 'gemini',
        'api_key' => env('GEMINI_API_KEY'),
        'driver' => \LarAgent\Drivers\OpenAi\GeminiDriver::class,
        'default_context_window' => 1000000,
        'default_max_completion_tokens' => 10000,
        'default_temperature' => 1,
        'model' => 'gemini-2.0-flash-latest',
    ],
],
```

Uses Gemini's OpenAI-compatible API endpoint.

### Groq Driver

```php
'providers' => [
    'groq' => [
        'label' => 'groq',
        'api_key' => env('GROQ_API_KEY'),
        'driver' => \LarAgent\Drivers\Groq\GroqDriver::class,
        'default_context_window' => 131072,
        'default_max_completion_tokens' => 131072,
        'default_temperature' => 1,
    ],
],
```

**Supported Features:**
- ✅ Chat completions
- ✅ Streaming
- ✅ Tool/function calling
- ✅ Structured output

### OpenRouter Driver

```php
'providers' => [
    'openrouter' => [
        'label' => 'openrouter',
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => 'openai/gpt-4o',
        'driver' => \LarAgent\Drivers\OpenAi\OpenRouter::class,
        'default_context_window' => 200000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 1,
    ],
],
```

Access multiple models through OpenRouter's unified API.

### Ollama Driver (Local)

```php
'providers' => [
    'ollama' => [
        'label' => 'ollama',
        'driver' => \LarAgent\Drivers\OpenAi\OllamaDriver::class,
        'default_context_window' => 131072,
        'default_max_completion_tokens' => 131072,
        'default_temperature' => 0.8,
        // Optional: custom URL (defaults to http://localhost:11434/v1)
        // 'api_url' => 'http://localhost:11434/v1',
    ],
],
```

**Requirements:** Ollama server running locally.

## Driver Configuration

### DriverConfig DTO

All drivers receive configuration through `DriverConfig`:

```php
use LarAgent\Core\DTO\DriverConfig;

$config = new DriverConfig(
    model: 'gpt-4o-mini',
    apiKey: 'your-api-key',
    apiUrl: 'https://api.openai.com/v1',
    maxCompletionTokens: 4096,
    temperature: 0.7,
    n: 1,
    topP: 1.0,
    frequencyPenalty: 0.0,
    presencePenalty: 0.0,
    parallelToolCalls: true,
    toolChoice: 'auto',
    modalities: ['text'],
    audio: null,
);

// Add custom parameters
$config = $config->withExtra([
    'seed' => 42,
    'logprobs' => true,
]);
```

### Setting Provider in Agent

```php
class MyAgent extends Agent
{
    // Use a specific provider from config
    protected $provider = 'claude';

    // Override model for this agent
    protected $model = 'claude-3-5-sonnet-latest';

    // Override other settings
    protected $temperature = 0.5;
    protected $maxCompletionTokens = 2000;
}
```

### Runtime Provider Change

```php
$agent = MyAgent::make();

// Change model
$agent->withModel('gpt-4o');

// Change temperature
$agent->temperature(0.8);

// Change max tokens
$agent->maxCompletionTokens(1000);
```

## Message Formatters

Each driver uses a message formatter to convert between LarAgent's message format and the provider's API format:

```php
// OpenAI formatter
use LarAgent\Drivers\OpenAi\OpenAiMessageFormatter;

// Claude formatter
use LarAgent\Drivers\Anthropic\ClaudeMessageFormatter;

// Gemini formatter
use LarAgent\Drivers\Gemini\GeminiMessageFormatter;
```

Formatters handle:
- Converting message arrays to API format
- Extracting content from responses
- Formatting tool calls and results
- Extracting usage information

## Fallback Provider

Configure a fallback provider for when the primary fails:

```php
// config/laragent.php
'fallback_provider' => 'groq',  // or null to disable
```

When the primary driver throws an exception, LarAgent automatically retries with the fallback:

```php
class MyAgent extends Agent
{
    protected $provider = 'openai';  // Primary
    // If OpenAI fails, uses groq (from config)
}
```

## Creating Custom Drivers

### Extend Base Driver

```php
<?php

namespace App\Drivers;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;

class CustomDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        // Initialize your client
        $this->client = new YourApiClient($this->getDriverConfig()->apiKey);
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        // Merge configs
        $config = $this->getDriverConfig()->merge(DriverConfig::wrap($overrideSettings));

        // Make API call
        $response = $this->client->chat([
            'model' => $config->model,
            'messages' => $this->formatMessages($messages),
            // ... other params
        ]);

        return new AssistantMessage($response->content);
    }

    public function sendMessageStreamed(
        array $messages,
        DriverConfig|array $overrideSettings = [],
        ?callable $callback = null
    ): \Generator {
        // Implement streaming...
        yield new StreamedAssistantMessage();
    }

    // Required: Format tool result for this API
    public function toolResultToMessage($toolCall, mixed $result): array
    {
        return [
            'role' => 'tool',
            'content' => json_encode($result),
            'tool_call_id' => $toolCall->getId(),
        ];
    }

    // Required: Format tool calls for this API
    public function toolCallsToMessage(array $toolCalls): array
    {
        return [
            'role' => 'assistant',
            'tool_calls' => array_map(/* format each call */, $toolCalls),
        ];
    }
}
```

### Register Custom Driver

```php
// config/laragent.php
'providers' => [
    'custom' => [
        'label' => 'custom',
        'api_key' => env('CUSTOM_API_KEY'),
        'driver' => \App\Drivers\CustomDriver::class,
        'model' => 'custom-model-v1',
        'default_context_window' => 32000,
        'default_max_completion_tokens' => 4096,
        'default_temperature' => 0.7,
    ],
],
```

## Usage Data from Drivers

Drivers extract usage information from API responses:

```php
// After a response, usage is available on the message
$response = $agent->respond('Hello');
$lastMessage = $agent->lastMessage();

if ($lastMessage && method_exists($lastMessage, 'getUsage')) {
    $usage = $lastMessage->getUsage();
    if ($usage) {
        echo "Prompt tokens: " . $usage->promptTokens;
        echo "Completion tokens: " . $usage->completionTokens;
        echo "Total tokens: " . $usage->totalTokens;
    }
}
```

## Tool Support

Drivers format tools for their specific APIs:

```php
// OpenAI format (default)
public function formatToolForPayload($tool): array
{
    return [
        'type' => 'function',
        'function' => [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'parameters' => [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ],
        ],
    ];
}

// Claude format (Anthropic)
public function formatToolForPayload($tool): array
{
    return [
        'name' => $tool->getName(),
        'description' => $tool->getDescription(),
        'input_schema' => [
            'type' => 'object',
            'properties' => $tool->getProperties(),
            'required' => $tool->getRequired(),
        ],
    ];
}
```

## Feature Matrix

| Feature | OpenAI | Claude | Gemini | Groq | Ollama |
|---------|--------|--------|--------|------|--------|
| Chat | ✅ | ✅ | ✅ | ✅ | ✅ |
| Streaming | ✅ | ✅ | ✅ | ✅ | ✅ |
| Tools | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| Structured Output | ✅ | ❌ | ✅ | ✅ | ⚠️ |
| Vision | ✅ | ✅ | ✅ | ⚠️ | ⚠️ |
| Audio | ✅ | ❌ | ❌ | ❌ | ❌ |

⚠️ = Depends on model
