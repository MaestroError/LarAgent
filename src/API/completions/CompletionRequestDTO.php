<?php

namespace LarAgent\API\completions;

use ArrayAccess;
use JsonSerializable;

class CompletionRequestDTO implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly ?array $modalities = null,
        public readonly ?array $audio = null,
        public readonly ?int $n = null,
        public readonly ?float $temperature = null,
        public readonly ?float $top_p = null,
        public readonly ?float $frequency_penalty = null,
        public readonly ?float $presence_penalty = null,
        public readonly ?int $max_completion_tokens = null,
        public readonly ?array $response_format = null,
        public readonly ?array $tools = null,
        public readonly mixed $tool_choice = null,
        public readonly ?bool $parallel_tool_calls = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            messages: $data['messages'],
            model: $data['model'],
            modalities: $data['modalities'] ?? null,
            audio: $data['audio'] ?? null,
            n: $data['n'] ?? null,
            temperature: $data['temperature'] ?? null,
            top_p: $data['top_p'] ?? null,
            frequency_penalty: $data['frequency_penalty'] ?? null,
            presence_penalty: $data['presence_penalty'] ?? null,
            max_completion_tokens: $data['max_completion_tokens'] ?? null,
            response_format: $data['response_format'] ?? null,
            tools: $data['tools'] ?? null,
            tool_choice: $data['tool_choice'] ?? null,
            parallel_tool_calls: $data['parallel_tool_calls'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'messages' => $this->messages,
            'model' => $this->model,
            'modalities' => $this->modalities,
            'audio' => $this->audio,
            'n' => $this->n,
            'temperature' => $this->temperature,
            'top_p' => $this->top_p,
            'frequency_penalty' => $this->frequency_penalty,
            'presence_penalty' => $this->presence_penalty,
            'max_completion_tokens' => $this->max_completion_tokens,
            'response_format' => $this->response_format,
            'tools' => $this->tools,
            'tool_choice' => $this->tool_choice,
            'parallel_tool_calls' => $this->parallel_tool_calls,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet($offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \BadMethodCallException('CompletionRequestDTO is immutable.');
    }

    public function offsetUnset($offset): void
    {
        throw new \BadMethodCallException('CompletionRequestDTO is immutable.');
    }
}
