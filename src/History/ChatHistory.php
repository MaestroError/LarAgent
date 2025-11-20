<?php

namespace LarAgent\History;

use LarAgent\Core\Abstractions\ChatHistory as HistoryAbstraction;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class ChatHistory extends HistoryAbstraction implements ChatHistoryInterface
{
    protected array $storage = [];

    protected array $keyStorage = [];

    public function readFromMemory(): void
    {
        $this->setMessages($this->storage[$this->getIdentifier()] ?? []);
    }

    public function writeToMemory(): void
    {
        $this->storage[$this->getIdentifier()] = $this->getMessages();
    }

    public function saveKeyToMemory(): void
    {
        $key = $this->getIdentifier();
        if (! in_array($key, $this->keyStorage)) {
            $this->keyStorage[] = $key;
        }
    }

    public function loadKeysFromMemory(): array
    {
        return $this->keyStorage;
    }

    public function removeChatFromMemory(string $key): void
    {
        unset($this->storage[$key]);
        $this->removeChatKey($key);
    }

    protected function removeChatKey(string $key): void
    {
        $this->keyStorage = array_filter($this->keyStorage, fn ($k) => $k !== $key);
    }
}
