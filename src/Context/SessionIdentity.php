<?php

namespace LarAgent\Context;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

class SessionIdentity implements SessionIdentityContract
{
    protected string $key;

    public function __construct(
        public readonly string $agentName,
        public readonly ?string $chatName = null,
        public readonly ?string $userId = null,
        public readonly ?string $group = null
    ) {
        $this->key = $this->generateKey();
    }

    /**
     * Create instance from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentName: $data['agentName'] ?? '',
            chatName: $data['chatName'] ?? '',
            userId: $data['userId'] ?? '',
            group: $data['group'] ?? ''
        );
    }

    /**
     * Convert DM to array
     */
    public function toArray(): array
    {
        return [
            'agentName' => $this->agentName,
            'chatName' => $this->chatName,
            'userId' => $this->userId,
            'group' => $this->group,
            'key' => $this->getKey(),
        ];
    }
    

    /**
     * Build the storage key from identity components
     * Format: agentName_chatName
     */
    public function getKey(): string
    {
        return $this->key;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getChatName(): ?string
    {
        return $this->chatName;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    protected function generateKey(): string
    {
        return sprintf(
            '%s_%s',
            $this->group ?? $this->agentName,
            $this->userId ?? $this->chatName ?? 'default',
        );
    }
}
