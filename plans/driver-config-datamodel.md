# Driver Config DTO Implementation Plan

## Overview

Replace the array-based driver configuration with a `DriverConfig` DTO to provide cleaner, type-safe access to driver configurations while maintaining backward compatibility with the existing system.

## Goals

1. **Type-safe configuration** - Use DTO properties instead of array keys
2. **Backward compatibility** - Keep existing provider configs and agent property overrides working
3. **Flexibility** - Allow arbitrary configs via `$extra` array (merged in `toArray()`)
4. **Clean API** - Provide clear, documented properties for all driver configurations

## Current System Analysis

### Current Config Flow

1. Provider configs defined in `config/laragent.php`
2. Agent class properties can override provider configs (`protected $model`, `protected $temperature`, etc.)
3. Dynamic overrides via `$this->configs` array and `withConfigs()` method
4. All merged in `buildConfigsFromAgent()` method and passed to driver

### Current Array Keys Used

-   `model` - LLM model name (required in practice, drivers have fallbacks)
-   `api_key` - API key for the provider
-   `api_url` - API URL (for custom endpoints)
-   `maxCompletionTokens` - Max tokens for completion
-   `temperature` - Sampling temperature
-   `n` - Number of completions
-   `topP` - Top-p sampling
-   `frequencyPenalty` - Frequency penalty
-   `presencePenalty` - Presence penalty
-   `parallelToolCalls` - Allow parallel tool calls
-   `toolChoice` - Tool choice configuration (string like 'auto'/'none' OR array for forced tool)
-   `modalities` - Response modalities
-   `audio` - Audio configuration

## Why DTO Instead of DataModel?

1. **Union type support** - `toolChoice` needs `string|array|null` which DataModel doesn't handle
2. **No schema generation needed** - Driver configs don't need OpenAPI schemas
3. **Simpler implementation** - Matches existing `AgentDTO` pattern
4. **Better fit** - DTOs are for data transfer, DataModels are for structured LLM outputs

## Proposed Solution

### Phase 1: Create DriverConfig DTO

Create `src/Core/DTO/DriverConfig.php`:

```php
<?php

namespace LarAgent\Core\DTO;

class DriverConfig
{
    public function __construct(
        public ?string $model = null,
        public ?string $api_key = null,
        public ?string $api_url = null,
        public ?int $maxCompletionTokens = null,
        public ?float $temperature = null,
        public ?int $n = null,
        public ?float $topP = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
        public ?bool $parallelToolCalls = null,
        public string|array|null $toolChoice = null,
        public ?array $modalities = null,
        public ?array $audio = null,
        private array $extra = [],
    ) {}

    /**
     * Create from array (provider config or agent config)
     */
    public static function fromArray(array $data): static
    {
        $known = [
            'model', 'api_key', 'api_url', 'maxCompletionTokens',
            'temperature', 'n', 'topP', 'frequencyPenalty',
            'presencePenalty', 'parallelToolCalls', 'toolChoice',
            'modalities', 'audio'
        ];

        $extra = array_diff_key($data, array_flip($known));

        return new static(
            model: $data['model'] ?? null,
            api_key: $data['api_key'] ?? null,
            api_url: $data['api_url'] ?? null,
            maxCompletionTokens: $data['maxCompletionTokens'] ?? null,
            temperature: $data['temperature'] ?? null,
            n: $data['n'] ?? null,
            topP: $data['topP'] ?? null,
            frequencyPenalty: $data['frequencyPenalty'] ?? null,
            presencePenalty: $data['presencePenalty'] ?? null,
            parallelToolCalls: $data['parallelToolCalls'] ?? null,
            toolChoice: $data['toolChoice'] ?? null,
            modalities: $data['modalities'] ?? null,
            audio: $data['audio'] ?? null,
            extra: $extra,
        );
    }

    /**
     * Add extra configurations
     */
    public function withExtra(array $extra): static
    {
        $clone = clone $this;
        $clone->extra = array_merge($clone->extra, $extra);
        return $clone;
    }

    /**
     * Get a specific extra config value
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Get all extra configs
     */
    public function getExtras(): array
    {
        return $this->extra;
    }

    /**
     * Merge with another DriverConfig (other takes precedence for non-null values)
     */
    public function merge(DriverConfig $other): static
    {
        return new static(
            model: $other->model ?? $this->model,
            api_key: $other->api_key ?? $this->api_key,
            api_url: $other->api_url ?? $this->api_url,
            maxCompletionTokens: $other->maxCompletionTokens ?? $this->maxCompletionTokens,
            temperature: $other->temperature ?? $this->temperature,
            n: $other->n ?? $this->n,
            topP: $other->topP ?? $this->topP,
            frequencyPenalty: $other->frequencyPenalty ?? $this->frequencyPenalty,
            presencePenalty: $other->presencePenalty ?? $this->presencePenalty,
            parallelToolCalls: $other->parallelToolCalls ?? $this->parallelToolCalls,
            toolChoice: $other->toolChoice ?? $this->toolChoice,
            modalities: $other->modalities ?? $this->modalities,
            audio: $other->audio ?? $this->audio,
            extra: array_merge($this->extra, $other->extra),
        );
    }

    /**
     * Convert to array for driver consumption
     * Filters out null values and merges extra configs
     */
    public function toArray(): array
    {
        $data = array_filter([
            'model' => $this->model,
            'api_key' => $this->api_key,
            'api_url' => $this->api_url,
            'maxCompletionTokens' => $this->maxCompletionTokens,
            'temperature' => $this->temperature,
            'n' => $this->n,
            'topP' => $this->topP,
            'frequencyPenalty' => $this->frequencyPenalty,
            'presencePenalty' => $this->presencePenalty,
            'parallelToolCalls' => $this->parallelToolCalls,
            'toolChoice' => $this->toolChoice,
            'modalities' => $this->modalities,
            'audio' => $this->audio,
        ], fn($value) => $value !== null);

        // Merge extra configs (extra values don't override known properties)
        return [...$data, ...$this->extra];
    }
}
```

### Phase 2: Update Agent Class

1. Update `buildConfigsFromAgent()` to return `DriverConfig` instead of array
2. Keep `$this->configs` as extra configs passed to `DriverConfig::withExtra()`
3. Update `initDriver()` to pass `DriverConfig` to driver constructor
4. Update `setupProviderData()` to work with `DriverConfig`

```php
protected function buildConfigsFromAgent(): DriverConfig
{
    $config = new DriverConfig(
        model: $this->model(),
        api_key: $this->getApiKey(),
        api_url: $this->getApiUrl(),
        maxCompletionTokens: $this->maxCompletionTokens ?? null,
        temperature: $this->temperature ?? null,
        n: $this->n ?? null,
        topP: $this->topP ?? null,
        frequencyPenalty: $this->frequencyPenalty ?? null,
        presencePenalty: $this->presencePenalty ?? null,
        parallelToolCalls: $this->parallelToolCalls ?? null,
        toolChoice: $this->toolChoice ?? null,
        modalities: !empty($this->modalities) ? $this->modalities : null,
        audio: !empty($this->audio) ? $this->audio : null,
    );

    return $config->withExtra($this->configs);
}

protected function initDriver(DriverConfig $config): void
{
    $this->llmDriver = new $this->driver($config);
}

protected function setupProviderData(): void
{
    $provider = $this->getProviderData();
    // ... existing logic for driver/history setup ...

    // Create DriverConfig from provider data, then merge agent overrides
    $providerConfig = DriverConfig::fromArray($provider);
    $agentConfig = $this->buildConfigsFromAgent();

    // Merge: agent config takes precedence over provider config
    $finalConfig = $providerConfig->merge($agentConfig);

    $this->initDriver($finalConfig);
}
```

### Phase 3: Update LlmDriver Abstraction

1. Change constructor to accept `DriverConfig` instead of array
2. Store `DriverConfig` as property for typed access
3. Keep `getSettings(): array` for backward compatibility (calls `$config->toArray()`)
4. Add `getDriverConfig(): DriverConfig` for typed access

```php
// src/Core/Abstractions/LlmDriver.php

abstract class LlmDriver implements LlmDriverInterface
{
    protected DriverConfig $driverConfig;

    // Backward compat: keep array version
    protected array $settings;

    public function __construct(DriverConfig $config)
    {
        $this->driverConfig = $config;
        $this->settings = $config->toArray(); // For backward compat
    }

    public function getDriverConfig(): DriverConfig
    {
        return $this->driverConfig;
    }

    // Keep existing method for backward compatibility
    public function getSettings(): array
    {
        return $this->settings;
    }
}
```

### Phase 4: Update Individual Drivers (Minimal Changes)

For now, drivers continue using `getSettings()` array access. They can gradually migrate to typed `getDriverConfig()` access.

```php
// Example: No changes needed in drivers initially
// They keep using $this->getSettings()['model'] etc.

// Future improvement (optional per driver):
// $this->getDriverConfig()->model
```

## Key Considerations

### Model Property

-   Nullable in DTO (`?string $model = null`)
-   Required in practice - all drivers have fallback defaults
-   Allows flexibility for edge cases

### Extra Configs Merging

-   Unknown keys from input arrays preserved in `$extra`
-   `toArray()` merges known properties with `$extra`
-   Extra values don't override known properties (known take precedence)

### Null Handling

-   All properties nullable with `null` defaults
-   `toArray()` filters out null values
-   Matches current behavior where unset properties aren't included

## Example Usage

```php
// Current API (still works - no changes for end users)
$agent->withConfigs(['custom_option' => true]);

// Inside Agent class
$config = $this->buildConfigsFromAgent(); // Returns DriverConfig
$this->initDriver($config); // Passes DriverConfig to driver

// Inside Driver - can use either approach
// Array access (backward compat, no changes needed):
$model = $this->getSettings()['model'];

// Typed access (future improvement):
$model = $this->getDriverConfig()->model;
$temp = $this->getDriverConfig()->temperature;

// Extra configs work in both modes
$custom = $this->getSettings()['custom_option'];
// or
$custom = $this->getDriverConfig()->getExtra('custom_option');
```

## Migration Path

1. **Non-breaking**: DriverConfig is internal, users continue using existing API
2. **Gradual adoption**: Drivers can start using typed access
3. **Future**: Optionally expose DriverConfig for advanced users

## Files to Create/Modify

### New Files

-   `src/Core/DTO/DriverConfig.php` - The DTO class

### Modified Files

-   `src/Agent.php` - Use DriverConfig in `buildConfigsFromAgent()`, `initDriver()`, `setupProviderData()`
-   `src/Core/Abstractions/LlmDriver.php` - Accept `DriverConfig` in constructor, add `getDriverConfig()`
-   `src/Drivers/OpenAi/BaseOpenAiDriver.php` - Update constructor to call parent with DriverConfig
-   `src/Drivers/Anthropic/ClaudeDriver.php` - Update constructor to call parent with DriverConfig
-   `src/Drivers/Gemini/GeminiDriver.php` - Update constructor to call parent with DriverConfig
-   `src/Drivers/Groq/GroqDriver.php` - Update constructor to call parent with DriverConfig

## Future Consideration: DataModel Union Type Support

If needed for structured outputs, DataModel could be enhanced to support union types:

```php
// In getTypeSchemaFromType()
if ($type instanceof ReflectionUnionType) {
    $types = [];
    foreach ($type->getTypes() as $unionType) {
        if ($unionType instanceof ReflectionNamedType) {
            $types[] = static::getTypeSchemaFromType($unionType);
        }
    }
    return ['oneOf' => $types];
}
```

This is a separate enhancement and not required for DriverConfig.

## Notes

-   No breaking changes to public API
-   Maintains all current flexibility
-   Improves internal code quality and type safety
-   DTO pattern matches existing `AgentDTO` in the codebase
