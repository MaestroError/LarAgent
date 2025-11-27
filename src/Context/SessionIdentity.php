<?php

namespace LarAgent\Context;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;

class SessionIdentity implements SessionIdentityContract
{
    protected string $key;

    /**
     * The scope for storage isolation (e.g., 'chat_history', 'state', 'memory')
     */
    protected ?string $scope = null;

    public function __construct(
        public readonly string $agentName,
        public readonly ?string $chatName = null,
        public readonly ?string $userId = null,
        public readonly ?string $group = null,
        ?string $scope = null
    ) {
        $this->scope = $scope;
        $this->key = $this->generateKey();
    }

    /**
     * Create instance from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentName: $data['agentName'] ?? '',
            chatName: !empty($data['chatName']) ? $data['chatName'] : null,
            userId: !empty($data['userId']) ? $data['userId'] : null,
            group: !empty($data['group']) ? $data['group'] : null,
            scope: !empty($data['scope']) ? $data['scope'] : null
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
            'scope' => $this->scope,
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

    /**
     * Get the current scope
     *
     * @return string|null
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Create a new identity with a scope appended to the key.
     * This allows different storage types to have isolated keys.
     *
     * @param string $scope The scope to append (e.g., 'chat_history', 'state', 'memory')
     * @return static A new identity instance with the scoped key
     */
    public function withScope(string $scope): static
    {
        return new static(
            agentName: $this->agentName,
            chatName: $this->chatName,
            userId: $this->userId,
            group: $this->group,
            scope: $scope
        );
    }

    protected function generateKey(): string
    {
        $baseKey = sprintf(
            '%s_%s',
            $this->group ?? $this->agentName,
            $this->userId ?? $this->chatName ?? 'default',
        );

        // Append scope if present
        if ($this->scope !== null) {
            return sprintf('%s_%s', $this->scope, $baseKey);
        }

        return $baseKey;
    }
}
