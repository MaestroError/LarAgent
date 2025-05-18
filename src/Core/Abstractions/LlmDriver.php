<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;

abstract class LlmDriver implements LlmDriverInterface
{
    protected array $config = [];

    protected ?array $responseSchema = null;

    protected mixed $lastResponse = null;

    protected array $tools = [];

    protected array $settings;

    public function registerTool(ToolInterface $tool): self
    {
        $name = $tool->getName();
        $this->tools[$name] = $tool;

        return $this;
    }

    public function getRegisteredTools(): array
    {
        return $this->tools;
    }

    public function getTool(string $name): ToolInterface
    {
        return $this->tools[$name];
    }

    public function setResponseSchema(array $schema): self
    {
        $this->responseSchema = $schema;

        return $this;
    }

    public function getResponseSchema(): ?array
    {
        return $this->responseSchema;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLastResponse(): ?array
    {
        return $this->lastResponse;
    }

    protected function getRegisteredFunctions(): array
    {
        return array_map(fn (ToolInterface $tool) => $this->formatToolForPayload($tool), $this->tools);
    }

    public function structuredOutputEnabled(): bool
    {
        return ! empty($this->getResponseSchema());
    }

    /**
     * Get the provider data merged with the model defined settings.
     * Some Model settings override provider settings.
     *
     * @return array The settings.
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    /**
     * Format a tool for the API payload.
     * This method defines the structure of a tool for the specific LLM API.
     * Override this method in driver implementations to customize the tool format.
     */
    public function formatToolForPayload(ToolInterface $tool): array
    {
        // Default OpenAI-compatible format
        $toolSchema = [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
            ],
        ];
        if (! empty($tool->getProperties())) {
            $toolSchema['function']['parameters'] = [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }

    abstract public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array;

    abstract public function toolCallsToMessage(array $toolCalls): array;
}
