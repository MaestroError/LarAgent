<?php

namespace LarAgent\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Events\ChatHistory\ChatHistoryLoaded;
use LarAgent\Events\ChatHistory\ChatHistorySaving;
use LarAgent\Events\ChatHistory\ChatHistorySaved;
use LarAgent\Events\ChatHistory\MessageAdding;
use LarAgent\Events\ChatHistory\MessageAdded;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

class ChatHistoryStorage extends Storage implements ChatHistoryInterface
{
    /**
     * Whether to store metadata with messages
     */
    protected bool $storeMeta = false;

    /**
     * Create a new ChatHistoryStorage instance
     *
     * @param array|string $driversConfig Configuration for storage drivers
     * @param SessionIdentityContract $identity The identity for this storage
     * @param bool $storeMeta Whether to store metadata (default: false)
     */
    public function __construct(
        array|string $driversConfig,
        SessionIdentityContract $identity,
        bool $storeMeta = false
    ) {
        parent::__construct($driversConfig, $identity);
        $this->storeMeta = $storeMeta;
    }

    /**
     * Get the DataModelArray class name for messages
     * 
     * @return string The fully qualified class name
     */
    protected function getDataModelClass(): string
    {
        return MessageArray::class;
    }

    /**
     * Get the storage prefix/scope for isolation.
     * 
     * @return string The storage prefix
     */
    public static function getStoragePrefix(): string
    {
        return 'chatHistory';
    }

    /**
     * Add a message to the chat history
     *
     * @param MessageInterface $message
     * @return void
     */
    public function addMessage(MessageInterface $message): void
    {
        // Dispatch MessageAdding event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new MessageAdding($this, $message));
        }

        $this->add($message);

        // Dispatch MessageAdded event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new MessageAdded($this, $message));
        }
    }

    /**
     * Get all messages from the chat history
     *
     * @return MessageArray
     */
    public function getMessages(): MessageArray
    {
        return $this->get();
    }

    /**
     * Get the last message in the chat history
     *
     * @return MessageInterface|null
     */
    public function getLastMessage(): ?MessageInterface
    {
        return $this->getLast();
    }

    /**
     * Convert messages to array format
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->getMessages()->toArray();
    }

    /**
     * Convert messages to array format with metadata
     *
     * @return array
     */
    public function toArrayWithMeta(): array
    {
        $messages = [];
        foreach ($this->getMessages() as $message) {
            $messageArray = $message->toArray();
            if ($message instanceof \LarAgent\Core\Abstractions\Message) {
                $messageArray['metadata'] = $message->getMetadata();
            }
            $messages[] = $messageArray;
        }
        return $messages;
    }

    /**
     * Get the identifier for this chat history
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identity->getKey();
    }

    /**
     * Enable or disable metadata storage
     *
     * @param bool $store
     * @return void
     */
    public function setStoreMeta(bool $store): void
    {
        $this->storeMeta = $store;
    }

    /**
     * Check if metadata storage is enabled
     *
     * @return bool
     */
    public function shouldStoreMeta(): bool
    {
        return $this->storeMeta;
    }

    /**
     * Force read from storage drivers (bypasses lazy loading)
     *
     * @return void
     */
    public function readFromMemory(): void
    {
        $this->load();
    }

    /**
     * Force write to storage drivers (bypasses dirty check)
     *
     * @return void
     */
    public function writeToMemory(): void
    {
        $this->writeItems();
        $this->dirty = false;
    }

    /**
     * Save messages to storage (only if changed)
     * Dispatches events before and after saving
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        // Dispatch ChatHistorySaving event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new ChatHistorySaving($this, $this->getMessages()));
        }

        $this->writeItems();
        $this->dirty = false;

        // Dispatch ChatHistorySaved event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new ChatHistorySaved($this));
        }
    }

    /**
     * Load messages from storage
     * Dispatches event after loading
     *
     * @return void
     */
    protected function load(): void
    {
        parent::load();

        // Dispatch ChatHistoryLoaded event
        if (class_exists('Illuminate\Support\Facades\Event')) {
            \Illuminate\Support\Facades\Event::dispatch(new ChatHistoryLoaded($this, $this->items));
        }
    }

    /**
     * Write items to storage
     * Handles metadata storage option
     *
     * @return void
     */
    protected function writeItems(): void
    {
        if ($this->storeMeta) {
            // Store with metadata
            $this->storageManager->save($this->identity, $this->toArrayWithMeta());
        } else {
            // Store without metadata (default)
            $this->storageManager->save($this->identity, $this->items->toArray());
        }
    }
}
