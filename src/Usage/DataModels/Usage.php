<?php

namespace LarAgent\Usage\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

class Usage extends DataModel
{
    #[Desc('Number of tokens in the prompt')]
    public int $promptTokens;

    #[Desc('Number of tokens in the completion')]
    public int $completionTokens;

    #[Desc('Total number of tokens used')]
    public int $totalTokens;

    // Metadata fields for persistent storage
    #[Desc('User ID associated with this usage')]
    public ?string $userId = null;

    #[Desc('Group associated with this usage')]
    public ?string $group = null;

    #[Desc('Chat name/session ID associated with this usage')]
    public ?string $chatName = null;

    #[Desc('Creation timestamp (ISO 8601 format)')]
    public ?string $createdAt = null;

    #[Desc('Model name used')]
    public ?string $model = null;

    #[Desc('Provider label used')]
    public ?string $provider = null;

    #[Desc('Agent name')]
    public ?string $agent = null;

    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?int $totalTokens = null,
        ?string $userId = null,
        ?string $group = null,
        ?string $chatName = null,
        ?string $createdAt = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $agent = null
    ) {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->totalTokens = $totalTokens ?? ($promptTokens + $completionTokens);
        $this->userId = $userId;
        $this->group = $group;
        $this->chatName = $chatName;
        $this->createdAt = $createdAt ?? (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
        $this->model = $model;
        $this->provider = $provider;
        $this->agent = $agent;
    }

    /**
     * Convert to array with snake_case keys (standard format).
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'user_id' => $this->userId,
            'group' => $this->group,
            'chat_name' => $this->chatName,
            'created_at' => $this->createdAt,
            'model' => $this->model,
            'provider' => $this->provider,
            'agent' => $this->agent,
        ];
    }

    /**
     * Create Usage from array.
     * Expects normalized keys: prompt_tokens, completion_tokens, total_tokens.
     * MessageFormatters are responsible for normalizing API-specific keys.
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['completion_tokens'] ?? 0),
            isset($data['total_tokens']) ? (int) $data['total_tokens'] : null,
            $data['user_id'] ?? null,
            $data['group'] ?? null,
            $data['chat_name'] ?? null,
            $data['created_at'] ?? null,
            $data['model'] ?? null,
            $data['provider'] ?? null,
            $data['agent'] ?? null
        );
    }
}
