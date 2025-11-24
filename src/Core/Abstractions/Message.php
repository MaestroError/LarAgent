<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use JsonSerializable;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;
use LarAgent\Messages\DataModels\MessageContent;

abstract class Message extends DataModel implements MessageInterface
{
    #[Desc('The role of the message sender')]
    public string|Role $role;  // Represents the sender or role (e.g., "user", "agent")

    #[Desc('The content of the message')]
    public null|string|MessageContent $content = null;  // The actual message content

    protected array $metadata;  // Additional data about the message

    private array $dynamicProperties = [];

    public function __construct(string|Role $role, string|array|MessageContent $content, array $metadata = [])
    {
        $this->role = $role;
        $this->content = is_array($content) ? new MessageContent($content) : $content;
        $this->metadata = $metadata;
    }

    // Implementation of MessageInterface methods
    public function getRole(): string
    {
        return $this->role instanceof Role ? $this->role->value : $this->role;
    }

    public function getContent(): string|array
    {
        if ($this->content instanceof MessageContent) {
            return $this->content->toArray();
        }
        return $this->content;
    }

    public function get(string $key): mixed
    {
        return $this->{$key} ?? null;
    }

    public function setContent(string|array|MessageContent $message): void
    {
        $this->content = $message;
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

    public function toArray(): array
    {
        $properties = parent::toArray();

        // Merge with dynamic properties
        if (!empty($this->dynamicProperties)) {
            $properties = array_merge($properties, $this->dynamicProperties);
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

    public function buildFromArray(array $data): self
    {
        self::validateRole($data['role'] ?? '');


        parent::fill($data);

        if (isset($data['metadata'])) {
            $this->metadata = $data['metadata'];
        }

        foreach ($data as $key => $value) {
            if (!property_exists($this, $key)) {
                $this->__set($key, $value);
            }
        }

        return $this;
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
        $content = $this->getContent();
        if (is_string($content)) {
            return $content;
        } else {
            return $content[0]['text'] ?? '';
        }
    }

    public function __set(string $name, $value): void
    {
        $this->dynamicProperties[$name] = $value;
    }

    public function __get(string $name)
    {
        return $this->dynamicProperties[$name] ?? null;
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
