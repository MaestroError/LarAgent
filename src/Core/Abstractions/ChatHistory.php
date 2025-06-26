<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Message;

abstract class ChatHistory implements ArrayAccess, ChatHistoryInterface
{
    protected array $messages = []; // Store messages as an array of MessageInterface

    protected int $contextWindow;   // Maximum allowed tokens in the context

    protected int $reservedForCompletion = 1000; // Reserved tokens for completion

    protected string $name; // History identifier

    protected bool $storeMeta; // Store metadata with messages, when using toArray method for storage

    protected bool $saveChatKeys; // Save chat keys to memory

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->readFromMemory();

        $this->contextWindow = $options['context_window'] ?? 60000;
        $this->storeMeta = $options['store_meta'] ?? false;
        $this->saveChatKeys = $options['save_chat_keys'] ?? true;

        if ($this->saveChatKeys) {
            $this->saveKeyToMemory();
        }
    }

    public function addMessage(MessageInterface $message): void
    {
        $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getIdentifier(): string
    {
        return $this->name;
    }

    public function getLastMessage(): ?MessageInterface
    {
        return end($this->messages) ?: null;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function toArray(): array
    {
        return array_map(fn (MessageInterface $message) => $message->toArray(), $this->messages);
    }

    public function toArrayWithMeta(): array
    {
        return array_map(fn (MessageInterface $message) => $message->toArrayWithMeta(), $this->messages);
    }

    protected function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool
    {
        return isset($this->messages[$offset]);
    }

    public function offsetGet($offset): ?MessageInterface
    {
        return $this->messages[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if (! $value instanceof MessageInterface) {
            throw new \InvalidArgumentException('Only instances of MessageInterface can be added to ChatHistory.');
        }

        if (is_null($offset)) {
            $this->messages[] = $value;
        } else {
            $this->messages[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->messages[$offset]);
    }

    // Abstract methods for memory handling
    abstract public function readFromMemory(): void;

    abstract public function writeToMemory(): void;

    // Abstract methods for keys management
    /**
     * Save the chat key to memory
     */
    abstract public function saveKeyToMemory(): void;

    /**
     * Load chat keys from memory
     */
    abstract public function loadKeysFromMemory(): array;

    /**
     * Completely Remove chat and it's key from memory
     */
    abstract public function removeChatFromMemory(string $key): void;

    abstract protected function removeChatKey(string $key): void;

    // Token management methods
    public function setContextWindow(int $tokens): void
    {
        $this->contextWindow = $tokens;
    }

    // You can get $tokens for comparison from usage->promptTokens
    public function exceedsContextWindow(int $tokens): bool
    {
        return $tokens > ($this->contextWindow - $this->reservedForCompletion);
    }

    public function truncateOldMessages(int $messagesCount): void
    {
        array_splice($this->messages, 0, $messagesCount);
    }

    /**
     * Build messages from an array of data.
     * Useful with json storage implementations.
     */
    protected function buildMessages(array $data): array
    {
        return array_map(function ($message) {
            return Message::fromArray($message);
        }, $data);
    }

    /**
     * Convert messages to an array for storage.
     * Useful with json storage implementations.
     */
    protected function toArrayForStorage(): array
    {
        if ($this->storeMeta) {
            return $this->toArrayWithMeta();
        }

        return $this->toArray();
    }
}
