<?php

namespace LarAgent;

use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\DataModels\ToolCallFunction;

class ToolCall extends DataModel implements ToolCallInterface
{
    #[Desc('Unique identifier for the tool call')]
    public string $id;

    #[Desc('Type of tool call, always "function"')]
    public string $type = 'function';

    #[Desc('Function details')]
    public ToolCallFunction $function;

    /**
     * Thought signature for Gemini thinking models.
     * Required for Gemini 3 function calling - must be passed back exactly as received.
     */
    #[ExcludeFromSchema]
    protected ?string $thoughtSignature = null;

    public function __construct(string $id, string $toolName, string $arguments = '{}', ?string $thoughtSignature = null)
    {
        $this->id = $id;
        $this->type = 'function';

        // Validate that $arguments is valid JSON
        json_decode($arguments);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('ToolCall arguments must be a valid JSON string. Error: '.json_last_error_msg());
        }

        $this->function = new ToolCallFunction($toolName, $arguments);
        $this->thoughtSignature = $thoughtSignature;
    }

    /**
     * Create a ToolCall instance from an array.
     */
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? '';
        $type = $data['type'] ?? 'function';
        $function = ToolCallFunction::fromArray($data['function'] ?? []);
        $thoughtSignature = $data['thought_signature'] ?? null;

        $instance = new static($id, $function->name, $function->arguments, $thoughtSignature);
        $instance->type = $type;

        return $instance;
    }

    /**
     * Convert the ToolCall to an array.
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'function' => $this->function->toArray(),
        ];

        // Include thought signature if present (for storage and Gemini API)
        if ($this->thoughtSignature !== null) {
            $result['thought_signature'] = $this->thoughtSignature;
        }

        return $result;
    }

    // ToolCallInterface methods
    public function getId(): string
    {
        return $this->id;
    }

    public function getToolName(): string
    {
        return $this->function->name;
    }

    public function getArguments(): string
    {
        return $this->function->arguments;
    }

    /**
     * Get the thought signature for this tool call.
     * Used by Gemini thinking models.
     */
    public function getThoughtSignature(): ?string
    {
        return $this->thoughtSignature;
    }

    /**
     * Set the thought signature for this tool call.
     * Used by Gemini thinking models.
     *
     * @return $this
     */
    public function setThoughtSignature(?string $signature): static
    {
        $this->thoughtSignature = $signature;

        return $this;
    }

    /**
     * Check if this tool call has a thought signature.
     */
    public function hasThoughtSignature(): bool
    {
        return $this->thoughtSignature !== null;
    }
}
