<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Core\Contracts\DataModel as DataModelContract;

interface Message
{
    /**
     * Get unique message identifier
     */
    public function getId(): string;

    /**
     * Get message role
     */
    public function getRole(): string;

    /**
     * Get message content as DataModel
     */
    public function getContent(): ?DataModelContract;

    /**
     * Set message content
     */
    public function setContent(?DataModelContract $content): void;

    /**
     * Get content as plain string (convenience method)
     */
    public function getContentAsString(): string;

    /**
     * Get arbitrary property value
     */
    public function get(string $key): mixed;

    /**
     * Get message metadata
     */
    public function getMetadata(): array;

    /**
     * Set message metadata
     */
    public function setMetadata(array $data): void;

    /**
     * Convert to canonical array format
     */
    public function toArray(): array;

    /**
     * Convert to array including metadata
     */
    public function toArrayWithMeta(): array;

    /**
     * JSON serialization
     */
    public function jsonSerialize(): array;
}
