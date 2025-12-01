<?php

namespace LarAgent\Core\DTO;

class DriverConfig
{
    private array $extra = [];

    public function __construct(
        public ?string $model = null,
        public ?string $apiKey = null,
        public ?string $apiUrl = null,
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
        array $extra = [],
    ) {
        $this->extra = $extra;
    }

    /**
     * Known configuration keys (camelCase only)
     */
    protected static function knownKeys(): array
    {
        return [
            'model',
            'apiKey',
            'apiUrl',
            'maxCompletionTokens',
            'temperature',
            'n',
            'topP',
            'frequencyPenalty',
            'presencePenalty',
            'parallelToolCalls',
            'toolChoice',
            'modalities',
            'audio',
        ];
    }

    /**
     * Create from array (camelCase keys)
     */
    public static function fromArray(array $data): static
    {
        $extra = array_diff_key($data, array_flip(static::knownKeys()));

        return new static(
            model: $data['model'] ?? null,
            apiKey: $data['apiKey'] ?? null,
            apiUrl: $data['apiUrl'] ?? null,
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
     * Create from array or DriverConfig for override settings.
     * Useful when accepting either type in method signatures.
     */
    public static function wrap(DriverConfig|array $data): static
    {
        if ($data instanceof static) {
            return $data;
        }

        return static::fromArray($data);
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
            apiKey: $other->apiKey ?? $this->apiKey,
            apiUrl: $other->apiUrl ?? $this->apiUrl,
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
     * Filters out null values and uses camelCase keys
     */
    public function toArray(): array
    {
        $data = array_filter([
            'model' => $this->model,
            'apiKey' => $this->apiKey,
            'apiUrl' => $this->apiUrl,
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
        ], fn ($value) => $value !== null);

        // Merge extra configs (extra values don't override known properties)
        return [...$data, ...$this->extra];
    }

    /**
     * Check if a known property has a value (not null)
     */
    public function has(string $property): bool
    {
        if (! property_exists($this, $property)) {
            return isset($this->extra[$property]);
        }

        return $this->$property !== null;
    }

    /**
     * Get any property (known or extra) with optional default
     */
    public function get(string $property, mixed $default = null): mixed
    {
        if (property_exists($this, $property) && $property !== 'extra') {
            return $this->$property ?? $default;
        }

        return $this->extra[$property] ?? $default;
    }
}
