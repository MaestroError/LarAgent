<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;

abstract class LlmDriver implements LlmDriverInterface
{
    protected ?array $responseSchema = null;

    protected mixed $lastResponse = null;

    protected array $tools = [];

    protected DriverConfig $driverConfig;

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
     * Get the settings as an array for backward compatibility.
     * Prefer using getDriverConfig() for typed access.
     *
     * @return array The settings as array.
     */
    public function getSettings(): array
    {
        return $this->driverConfig->toArray();
    }

    /**
     * Get the DriverConfig instance for typed access to configuration.
     */
    public function getDriverConfig(): DriverConfig
    {
        return $this->driverConfig;
    }

    /**
     * Constructor accepts either DriverConfig or array for backward compatibility.
     *
     * @param  DriverConfig|array  $settings  Configuration for the driver
     */
    public function __construct(DriverConfig|array $settings = [])
    {
        $this->driverConfig = DriverConfig::wrap($settings);
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

    /**
     * Format an array of image URLs for the API payload.
     * This method defines the structure of images for the specific LLM API.
     * Override this method in driver implementations to customize the image format.
     */
    public function formatImagesForPayload(?array $images = null): array
    {
        if ($images === null) {
            throw new \Exception('No images provided to formatImagesForPayload().');
        }

        $formattedImages = [];

        // Default OpenAI-compatible format
        foreach ($images as $url) {
            $formattedImages[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $url,
                ],
            ];
        }

        return $formattedImages;
    }

    abstract public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array;

    abstract public function toolCallsToMessage(array $toolCalls): array;
}
