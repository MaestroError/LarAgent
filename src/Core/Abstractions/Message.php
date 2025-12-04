<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;

abstract class Message extends DataModel implements MessageInterface
{
    #[ExcludeFromSchema]
    public string $message_uuid;

    /**
     * Timestamp when the message was created.
     * Stored as ISO 8601 formatted string for easy serialization.
     */
    #[ExcludeFromSchema]
    public string $message_created;

    #[Desc('The role of the message sender')]
    public string|Role $role;  // NO DEFAULT - children will add their fixed value

    // NO $content property - each child defines its own as DataModelContract

    protected array $metadata = [];  // Additional data about the message

    /**
     * Extra fields not defined in class properties.
     * Stores driver-specific or unknown fields from deserialization.
     * Excluded from schema (not sent to LLM API).
     */
    #[ExcludeFromSchema]
    protected array $extras = [];

    public function __construct()
    {
        // Auto-generate ID if not set
        if (! isset($this->message_uuid)) {
            $this->message_uuid = $this->generateId();
        }

        // Auto-set creation timestamp if not set
        if (! isset($this->message_created)) {
            $this->message_created = $this->generateTimestamp();
        }
    }

    protected function generateId(): string
    {
        return 'msg_'.bin2hex(random_bytes(12));
    }

    /**
     * Generate ISO 8601 timestamp for message creation
     */
    protected function generateTimestamp(): string
    {
        return (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
    }

    /**
     * Get unique message identifier
     */
    public function getId(): string
    {
        return $this->message_uuid;
    }

    /**
     * Get message creation timestamp as ISO 8601 string
     */
    public function getCreatedAt(): string
    {
        return $this->message_created;
    }

    /**
     * Get message creation timestamp as DateTimeImmutable
     */
    public function getCreatedAtDateTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->message_created);
    }

    // Implementation of MessageInterface methods
    public function getRole(): string
    {
        return $this->role instanceof Role ? $this->role->value : $this->role;
    }

    /**
     * Get message content - children implement with proper DataModel types
     */
    abstract public function getContent(): ?DataModelContract;

    /**
     * Set message content - children implement with proper DataModel types
     */
    abstract public function setContent(?DataModelContract $content): void;

    /**
     * Get content as string (for simple text extraction)
     */
    public function getContentAsString(): string
    {
        $content = $this->getContent();
        if ($content === null) {
            return '';
        }

        // Delegate to content's string representation
        return (string) $content;
    }

    public function get(string $key): mixed
    {
        return $this->{$key} ?? null;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $data): void
    {
        $this->metadata = $data;
    }

    public function addMeta(array $data): void
    {
        $this->metadata = array_merge($this->metadata, $data);
    }

    // ========== Extras Management ==========

    /**
     * Get all extra fields
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    /**
     * Set all extra fields
     */
    public function setExtras(array $extras): void
    {
        $this->extras = $extras;
    }

    /**
     * Get a single extra field
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }

    /**
     * Set a single extra field
     */
    public function setExtra(string $key, mixed $value): void
    {
        $this->extras[$key] = $value;
    }

    /**
     * Check if an extra field exists
     */
    public function hasExtra(string $key): bool
    {
        return array_key_exists($key, $this->extras);
    }

    /**
     * Remove an extra field
     */
    public function removeExtra(string $key): void
    {
        unset($this->extras[$key]);
    }

    // ========== Serialization ==========

    public function toArray(): array
    {
        $properties = parent::toArray();

        // Include message_uuid in output (for storage)
        $properties['message_uuid'] = $this->message_uuid;

        // Include message_created in output (for storage)
        $properties['message_created'] = $this->message_created;

        // Include extras if not empty
        if (! empty($this->extras)) {
            $properties['extras'] = $this->extras;
        }

        return $properties;
    }

    public function toArrayWithMeta(): array
    {
        return [
            ...$this->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArrayWithMeta();
    }

    // Utility methods

    /**
     * @deprecated Use static fromArray() instead
     */
    public function buildFromArray(array $data): self
    {
        return self::fromArray($data);
    }

    /**
     * Create a new instance from an array of attributes.
     */
    public static function fromArray(array $data): static
    {
        static::validateRole($data['role'] ?? '');

        $instance = parent::fromArray($data);

        // Handle message_uuid - use from data or generate new
        if (isset($data['message_uuid'])) {
            $instance->message_uuid = $data['message_uuid'];
        } elseif (! isset($instance->message_uuid)) {
            $instance->message_uuid = $instance->generateId();
        }

        // Handle message_created - use from data or generate new
        if (isset($data['message_created'])) {
            $instance->message_created = $data['message_created'];
        } elseif (! isset($instance->message_created)) {
            $instance->message_created = $instance->generateTimestamp();
        }

        if (isset($data['metadata'])) {
            $instance->metadata = $data['metadata'];
        }

        // Handle extras - merge stored extras and capture unknown fields
        if (isset($data['extras'])) {
            $instance->extras = array_merge($instance->extras, $data['extras']);
        }

        // Use cached config to get known property names (already collected by DataModel)
        $config = static::getCachedConfig();
        $knownProperties = array_keys($config['properties']);
        // Also exclude metadata and extras from being added to extras
        $knownProperties[] = 'metadata';
        $knownProperties[] = 'extras';

        // Any array key not matching a known property goes to extras
        foreach ($data as $key => $value) {
            if (! in_array($key, $knownProperties)) {
                $instance->extras[$key] = $value;
            }
        }

        return $instance;
    }

    public function buildFromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        return $this->buildFromArray($data);
    }

    // Implementation of ArrayAccess

    public function offsetExists($offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \BadMethodCallException('Message is immutable.');
    }

    public function offsetUnset($offset): void
    {
        throw new \BadMethodCallException('Message is immutable.');
    }

    // Additional
    public function __toString(): string
    {
        return $this->getContentAsString();
    }

    protected static function validateRole(string $role): void
    {
        if (empty($role)) {
            throw new \InvalidArgumentException('Role cannot be empty.');
        }

        // Validate role using the Role enum
        $roleEnum = Role::tryFrom($role);

        if (! $roleEnum) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }
    }
}
