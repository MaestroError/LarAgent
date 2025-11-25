<?php

namespace LarAgent\Context\Contracts;

interface SessionIdentity
{
    public function getAgentName(): string;
    public function getChatName(): ?string;
    public function getUserId(): ?string;
    public function getGroup(): ?string;
    public function getKey(): string;
}
