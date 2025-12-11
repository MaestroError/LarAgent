<?php

namespace LarAgent\Usage\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Context\Contracts\SessionIdentity;

/**
 * Extended Usage DataModel that captures metadata for tracking and analytics.
 *
 * This class extends the base Usage to include:
 * - Identity information (user, group, agent name)
 * - Provider/model information
 * - Timestamps for filtering and aggregation
 */
class UsageRecord extends Usage
{
    #[ExcludeFromSchema]
    public string $recordId;

    #[Desc('Name of the agent that generated this usage')]
    public string $agentName;

    #[Desc('User ID associated with this usage (if user-based)')]
    public ?string $userId = null;

    #[Desc('Group identifier for this usage')]
    public ?string $group = null;

    #[Desc('Chat/session name for this usage')]
    public ?string $chatName = null;

    #[Desc('Name of the AI model used (e.g., gpt-4, claude-3)')]
    public string $modelName;

    #[Desc('Name of the provider label (e.g., openai, anthropic, gemini)')]
    public string $providerName;

    #[ExcludeFromSchema]
    public string $recordedAt;

    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?int $totalTokens = null,
        string $agentName = '',
        ?string $userId = null,
        ?string $group = null,
        ?string $chatName = null,
        string $modelName = '',
        string $providerName = '',
        ?string $recordedAt = null,
        ?string $recordId = null
    ) {
        parent::__construct($promptTokens, $completionTokens, $totalTokens);

        $this->recordId = $recordId ?? $this->generateId();
        $this->agentName = $agentName;
        $this->userId = $userId;
        $this->group = $group;
        $this->chatName = $chatName;
        $this->modelName = $modelName;
        $this->providerName = $providerName;
        $this->recordedAt = $recordedAt ?? $this->generateTimestamp();
    }

    /**
     * Generate unique identifier for usage record.
     */
    protected function generateId(): string
    {
        return 'usage_'.bin2hex(random_bytes(12));
    }

    /**
     * Generate ISO 8601 timestamp.
     */
    protected function generateTimestamp(): string
    {
        return (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
    }

    /**
     * Create UsageRecord from a base Usage object with additional metadata.
     */
    public static function fromUsage(
        Usage $usage,
        SessionIdentity $identity,
        string $modelName,
        string $providerName
    ): static {
        return new static(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            totalTokens: $usage->totalTokens,
            agentName: $identity->getAgentName(),
            userId: $identity->getUserId(),
            group: $identity->getGroup(),
            chatName: $identity->getChatName(),
            modelName: $modelName,
            providerName: $providerName
        );
    }

    /**
     * Get recorded timestamp as DateTimeImmutable.
     */
    public function getRecordedAtDateTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->recordedAt);
    }

    /**
     * Convert to array with snake_case keys.
     */
    public function toArray(): array
    {
        return [
            'record_id' => $this->recordId,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'agent_name' => $this->agentName,
            'user_id' => $this->userId,
            'group' => $this->group,
            'chat_name' => $this->chatName,
            'model_name' => $this->modelName,
            'provider_name' => $this->providerName,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * Create UsageRecord from array.
     */
    public static function fromArray(array $data): static
    {
        $promptTokens = (int) ($data['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['completion_tokens'] ?? 0);

        // If total_tokens is not provided, calculate from prompt + completion
        $totalTokens = isset($data['total_tokens'])
            ? (int) $data['total_tokens']
            : $promptTokens + $completionTokens;

        // Handle recorded_at - convert DateTimeInterface (including Carbon) to string
        $recordedAt = $data['recorded_at'] ?? null;
        if ($recordedAt instanceof \DateTimeInterface) {
            $recordedAt = $recordedAt->format(\DateTimeInterface::ATOM);
        }

        return new static(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            agentName: $data['agent_name'] ?? '',
            userId: $data['user_id'] ?? null,
            group: $data['group'] ?? null,
            chatName: $data['chat_name'] ?? null,
            modelName: $data['model_name'] ?? '',
            providerName: $data['provider_name'] ?? '',
            recordedAt: $recordedAt,
            recordId: $data['record_id'] ?? null
        );
    }
}
