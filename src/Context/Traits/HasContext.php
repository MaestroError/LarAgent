<?php

namespace LarAgent\Context\Traits;

use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\Context;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

trait HasContext
{

    protected Context $context;

    protected SessionIdentityContract $sessionIdentity;

    protected bool $usesUserId = false;


    /** @var string */
    protected string $chatSessionId;
    
    /**
     * Chat key associated with this agent
     *
     * @var string|null
     */
    protected ?string $userId;

    /**
     * Chat key associated with this agent
     *
     * @var string
     */
    protected string $chatKey;

    /**
     * Name of agent using the context
     *
     * @var string
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
            chatName: $this->getChatKey(),
            userId: $this->getUserId(),
            group: $this->group(),
        );
    }

    protected function setupContext(array $driversConfig = []): void
    {
        $identity = isset($this->sessionIdentity) ? $this->sessionIdentity : $this->buildIdentity();
        $this->context = new Context($identity, $driversConfig);
        // Explicitly load context identity data
        $this->context->getContextIdentity()->read();
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

    public function getChatKey(): string
    {
        return $this->chatKey;
    }

    public function getChatSessionId(): string
    {
        return $this->chatSessionId;
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

    public function setGroup(string $group): static
    {
        $this->group = $group;
        return $this;
    }

    public function context(): Context
    {
        return $this->context;
    }
}