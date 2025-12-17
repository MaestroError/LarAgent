<?php

namespace LarAgent\Context\Traits;

use LarAgent\Context\Context;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\SessionIdentity;

trait HasContext
{
    protected Context $context;

    protected SessionIdentityContract $sessionIdentity;

    protected bool $usesUserId = false;

    protected string $chatSessionId;

    /**
     * Chat key associated with this agent
     */
    protected ?string $userId;

    /**
     * Chat key associated with this agent
     */
    protected string $chatKey;

    /**
     * Name of agent using the context
     */
    protected string $agentName;

    /**
     * Name of group using the context
     *
     * @var string|null
     */
    protected $group = null;

    protected function setChatSessionId(string $id, string $agentName): static
    {
        if ($this->hasUserId()) {
            $this->userId = $id;
        }
        $this->agentName = $agentName;
        $this->chatKey = $id;
        $this->chatSessionId = $this->buildSessionId();

        return $this;
    }

    protected function buildSessionId(): string
    {
        $this->sessionIdentity = $this->buildIdentity();

        return $this->sessionIdentity->getKey();
    }

    protected function buildIdentity(): SessionIdentityContract
    {
        return new SessionIdentity(
            agentName: $this->getAgentName(),
            chatName: $this->getSessionKey(),
            userId: $this->getUserId(),
            group: $this->group(),
        );
    }

    protected function setupContext(array $driversConfig = []): void
    {
        $identity = isset($this->sessionIdentity) ? $this->sessionIdentity : $this->buildIdentity();
        $this->context = new Context($identity, $driversConfig);
        // Explicitly load context identity data
        $this->context->getIdentityStorage()->read();
    }

    public function usesUserId(): static
    {
        $this->usesUserId = true;

        return $this;
    }

    public function hasUserId(): bool
    {
        return $this->usesUserId;
    }

    public function getSessionKey(): string
    {
        return $this->chatKey;
    }

    /**
     * @deprecated Use getSessionKey() instead
     */
    public function getChatKey(): string
    {
        return $this->getSessionKey();
    }

    public function getSessionId(): string
    {
        return $this->chatSessionId;
    }

    /**
     * @deprecated Use getSessionId() instead
     */
    public function getChatSessionId(): string
    {
        return $this->getSessionId();
    }

    public function getUserId(): ?string
    {
        return $this->userId ?? null;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function group(): ?string
    {
        return $this->group;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
