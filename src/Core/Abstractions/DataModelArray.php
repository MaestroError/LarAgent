<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;
use ArrayAccess;
use IteratorAggregate;
use Countable;
use JsonSerializable;
use ArrayIterator;
use Traversable;
use InvalidArgumentException;
use ReflectionClass;

abstract class DataModelArray implements DataModelArrayContract
{
    /**
     * @var DataModelContract[]
     */
    protected array $items = [];

    /**
     * Return the list of allowed DataModel classes.
     *
     * @return array<string>
     */
    abstract public static function allowedModels(): array;

    /**
     * Return the discriminator field name for polymorphic arrays.
     *
     * @return string
     */
    public function discriminator(): string
    {
        return 'type';
    }

    public function __construct(mixed ...$items)
    {
        // If a single argument is passed and it's a list (indexed array), treat it as the list of items.
        // Otherwise, treat the arguments themselves as the items.
        if (count($items) === 1 && is_array($items[0]) && array_is_list($items[0])) {
            $this->fill($items[0]);
        } else {
            $this->fill($items);
        }
    }

    public function fill(array $attributes): static
    {
        $this->items = [];
        $allowedModels = static::allowedModels();
        $discriminator = $this->discriminator();

        foreach ($attributes as $item) {
            if ($item instanceof DataModelContract) {
                // Verify it's an instance of an allowed model
                $isAllowed = false;
                foreach ($allowedModels as $modelClass) {
                    if ($item instanceof $modelClass) {
                        $isAllowed = true;
                        break;
                    }
                }
                if (!$isAllowed) {
                    throw new InvalidArgumentException("Item is not an instance of an allowed model.");
                }
                $this->items[] = $item;
                continue;
            }

            if (!is_array($item)) {
                throw new InvalidArgumentException("Item must be an array or DataModel instance.");
            }

            // Determine target class
            $targetClass = null;

            if (count($allowedModels) === 1) {
                $targetClass = $allowedModels[0];
            } else {
                // Polymorphic resolution
                if (!isset($item[$discriminator])) {
                    throw new InvalidArgumentException("Missing discriminator field '{$discriminator}' for polymorphic array.");
                }
                
                $typeValue = $item[$discriminator];
                
                // We need a way to map the discriminator value to the class.
                // By default, we can check if the allowedModels array is associative ['value' => Class::class]
                // or if we need to inspect the classes (e.g. if they have a 'type' property default value).
                // For simplicity and performance, let's assume allowedModels can be associative for mapping,
                // or we check a static property/constant on the model, or we just try to match.
                
                // Let's support associative array in allowedModels for explicit mapping:
                // ['text' => TextContent::class, 'image' => ImageContent::class]
                if (isset($allowedModels[$typeValue])) {
                    $targetClass = $allowedModels[$typeValue];
                } else {
                    // Fallback: Check if allowedModels is a list and we can't map easily without instantiation or reflection
                    // Let's assume strict associative mapping for polymorphism for now as it's cleanest.
                    throw new InvalidArgumentException("Unknown discriminator value: {$typeValue}");
                }
            }

            if (!is_subclass_of($targetClass, DataModelContract::class)) {
                throw new InvalidArgumentException("Target class {$targetClass} must implement DataModelContract.");
            }

            $this->items[] = $targetClass::fromArray($item);
        }

        return $this;
    }

    public static function fromArray(array $attributes): static
    {
        // For DataModelArray, fromArray is essentially the same as constructing with the array
        // But we might receive the array directly as $attributes if it's a list,
        // OR we might receive ['items' => [...]] if it was nested? 
        // Usually castValue passes the raw array value.
        return new static($attributes);
    }

    public function toArray(): array
    {
        return array_map(function ($item) {
            return $item->toArray();
        }, $this->items);
    }

    public function toSchema(): array
    {
        return static::generateSchema();
    }

    public static function generateSchema(): array
    {
        $schema = [
            'type' => 'array',
            'items' => [],
        ];

        $allowedModels = static::allowedModels();

        if (count($allowedModels) === 1) {
            // Single type
            $class = reset($allowedModels);
            if (method_exists($class, 'generateSchema')) {
                $schema['items'] = $class::generateSchema();
            } else {
                try {
                    $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
                    $schema['items'] = $instance->toSchema();
                } catch (\Throwable $e) {
                    $schema['items'] = ['type' => 'object'];
                }
            }
        } else {
            // Polymorphic
            $schema['items']['oneOf'] = [];
            foreach ($allowedModels as $class) {
                if (method_exists($class, 'generateSchema')) {
                    $schema['items']['oneOf'][] = $class::generateSchema();
                } else {
                    try {
                        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
                        $schema['items']['oneOf'][] = $instance->toSchema();
                    } catch (\Throwable $e) {
                        // Skip or add generic object
                    }
                }
            }
        }

        return $schema;
    }

    // ArrayAccess Implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // IteratorAggregate Implementation
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    // Countable Implementation
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Add an item to the array.
     *
     * @param DataModelContract $item
     * @return static
     */
    public function add(DataModelContract $item): static
    {
        $this->offsetSet(null, $item);
        return $this;
    }

    /**
     * Remove an item from the array.
     *
     * @param mixed $itemOrKey The item to remove (value or index) or the key to check
     * @param mixed $value The value to check if removing by key/value pair
     * @return static
     */
    public function remove(mixed $itemOrKey, mixed $value = null): static
    {
        $isList = array_is_list($this->items);

        // Case 1: Remove by key/value pair
        if ($value !== null && is_string($itemOrKey)) {
            foreach ($this->items as $index => $item) {
                if ($item instanceof DataModelContract && isset($item[$itemOrKey]) && $item[$itemOrKey] === $value) {
                    unset($this->items[$index]);
                }
            }
            // Always re-index if it was a list or if we removed items
            if ($isList) {
                $this->items = array_values($this->items);
            }
            return $this;
        }

        // Case 2: Remove by index (int or string key)
        if (is_int($itemOrKey) || is_string($itemOrKey)) {
            if (isset($this->items[$itemOrKey])) {
                unset($this->items[$itemOrKey]);
                // If the array was a list, re-index it
                if ($isList) {
                    $this->items = array_values($this->items);
                }
            }
            return $this;
        }

        // Case 3: Remove by object instance
        $index = array_search($itemOrKey, $this->items, true);
        if ($index !== false) {
            unset($this->items[$index]);
            // If the array was a list, re-index it
            if ($isList) {
                $this->items = array_values($this->items);
            }
        }
        
        return $this;
    }

    /**
     * Get the first item.
     *
     * @return DataModelContract|null
     */
    public function first(): ?DataModelContract
    {
        if (empty($this->items)) {
            return null;
        }
        return reset($this->items);
    }

    /**
     * Get the last item.
     *
     * @return DataModelContract|null
     */
    public function last(): ?DataModelContract
    {
        if (empty($this->items)) {
            return null;
        }
        return end($this->items);
    }

    /**
     * Check if the array is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Clear all items.
     *
     * @return static
     */
    public function clear(): static
    {
        $this->items = [];
        return $this;
    }

    /**
     * Filter items using a callback.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $newItems = array_filter($this->items, $callback);
        // Re-index if it was a list
        if (array_is_list($this->items)) {
            $newItems = array_values($newItems);
        }
        
        // Create new instance with filtered items
        return new static($newItems);
    }

    /**
     * Map items using a callback.
     *
     * @param callable $callback
     * @return array
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    // JsonSerializable Implementation
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
