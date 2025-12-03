<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Messages\DataModels\MessageArray;

interface ChatHistory
{
    public function addMessage(MessageInterface $message): void;

    public function getMessages(): MessageArray;

    public function getIdentifier(): string;

    public function getLastMessage(): ?MessageInterface;

    public function clear(): void;

    public function count(): int;

    public function toArray(): array;

    public function readFromMemory(): void;

    public function writeToMemory(): void;
}
