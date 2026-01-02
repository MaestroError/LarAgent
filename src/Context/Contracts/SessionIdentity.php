<?php

namespace LarAgent\Context\Contracts;

interface SessionIdentity
{
    public function getAgentName(): string;

    public function getChatName(): ?string;

    public function getUserId(): ?string;

    public function getGroup(): ?string;

    public function getKey(): string;

    /**
     * Create a new identity with a scope appended to the key.
     * This allows different storage types to have isolated keys.
     *
     * @param  string  $scope  The scope to append (e.g., 'chat_history', 'state', 'memory')
     * @return static A new identity instance with the scoped key
     */
    public function withScope(string $scope): static;
}
