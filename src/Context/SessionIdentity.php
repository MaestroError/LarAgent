<?php

namespace LarAgent\Context;

class SessionIdentity
{
    public function __construct(
        public readonly string $agentName,
        public readonly ?string $chatName = null,
        public readonly ?string $userId = null
    ) {}

    /**
     * Build the storage key from identity components
     * Format: agentName_chatKey
     */
    public function getKey(): string
    {
        return sprintf(
            '%s_%s',
            $this->agentName,
            $this->chatName ?? $this->userId,
        );
    }

    /**
     * Create instance from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentName: $data['agentName'] ?? '',
            chatName: $data['chatName'] ?? '',
            userId: $data['userId'] ?? ''
        );
    }

    /**
     * Convert DTO to array
     */
    public function toArray(): array
    {
        return [
            'agentName' => $this->agentName,
            'chatName' => $this->chatName,
            'userId' => $this->userId,
        ];
    }
}
