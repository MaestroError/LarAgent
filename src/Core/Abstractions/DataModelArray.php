<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Contracts\DataModelArray as DataModelArrayContract;
use ReflectionClass;
use Traversable;

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
                $this->validateAllowedModel($item);
                $this->items[] = $item;

                continue;
            }

            if (! is_array($item)) {
                print_r($item);
                throw new InvalidArgumentException('Item must be an array or DataModel instance.');
            }

            $targetClass = $this->resolveTargetClass($item, $allowedModels, $discriminator);
            $this->items[] = $targetClass::fromArray($item);
        }

        return $this;
    }

    /**
     * Resolve the target class for a given item based on discriminator.
     */
    protected function resolveTargetClass(array $item, array $allowedModels, string $discriminator): string
    {
        // Single model - no discriminator needed
        if (count($allowedModels) === 1 && array_is_list($allowedModels)) {
            return $allowedModels[0];
        }

        if (! isset($item[$discriminator])) {
            throw new InvalidArgumentException("Missing discriminator field '{$discriminator}'.");
        }

        $discriminatorValue = $item[$discriminator];

        if (! isset($allowedModels[$discriminatorValue])) {
            throw new InvalidArgumentException("Unknown discriminator value: {$discriminatorValue}");
        }

        $target = $allowedModels[$discriminatorValue];

        // Single class - use directly
        if (is_string($target)) {
            return $target;
        }

        // Array of classes - resolve via matchesArray()
        if (is_array($target)) {
            return $this->resolveFromCandidates($target, $item);
        }

        throw new InvalidArgumentException("Invalid model mapping for: {$discriminatorValue}");
    }

    /**
     * Resolve target class from multiple candidates using matchesArray().
     */
    protected function resolveFromCandidates(array $candidates, array $item): string
    {
        foreach ($candidates as $class) {
            if (method_exists($class, 'matchesArray') && $class::matchesArray($item)) {
                return $class;
            }
        }

        // If no matchesArray method or none matched, use first as default
        return $candidates[0];
    }

    /**
     * Validate that an item is an instance of an allowed model.
     *
     * @throws InvalidArgumentException
     */
    protected function validateAllowedModel(DataModelContract $item): void
    {
        $allowedModels = static::allowedModels();
        $isAllowed = false;

        foreach ($allowedModels as $modelOrArray) {
            $classes = is_array($modelOrArray) ? $modelOrArray : [$modelOrArray];
            foreach ($classes as $class) {
                if ($item instanceof $class) {
                    $isAllowed = true;
                    break 2;
                }
            }
        }

        if (! $isAllowed) {
            throw new InvalidArgumentException('Item is not an instance of an allowed model.');
        }
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

        // Flatten allowedModels - extract all classes including those in nested arrays
        $allClasses = [];
        foreach ($allowedModels as $modelOrArray) {
            if (is_array($modelOrArray)) {
                foreach ($modelOrArray as $class) {
                    $allClasses[] = $class;
                }
            } else {
                $allClasses[] = $modelOrArray;
            }
        }

        if (count($allClasses) === 1) {
            // Single type
            $class = $allClasses[0];
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
            foreach ($allClasses as $class) {
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
     */
    public function add(DataModelContract $item): static
    {
        $this->offsetSet(null, $item);

        return $this;
    }

    /**
     * Find an item's index by a key/value match.
     * Internal helper reused by other methods.
     *
     * @param  string  $key  The property key to match
     * @param  mixed  $value  The value to match
     * @return int|null The index of the found item, or null
     */
    protected function findItem(string $key, mixed $value): ?int
    {
        foreach ($this->items as $index => $item) {
            if (isset($item[$key]) && $item[$key] === $value) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Get an item by a key/value match.
     *
     * @param  string  $key  The property key to match
     * @param  mixed  $value  The value to match
     */
    public function getItem(string $key, mixed $value): ?DataModelContract
    {
        $index = $this->findItem($key, $value);

        return $index !== null ? $this->items[$index] : null;
    }

    /**
     * Set (replace) an item by a key/value match.
     * If no matching item found, adds the new item.
     *
     * @param  string  $key  The property key to match
     * @param  mixed  $value  The value to match
     * @param  DataModelContract  $newItem  The new item to set
     */
    public function setItem(string $key, mixed $value, DataModelContract $newItem): static
    {
        $this->validateAllowedModel($newItem);

        $index = $this->findItem($key, $value);
        if ($index !== null) {
            $this->items[$index] = $newItem;
        } else {
            $this->items[] = $newItem;
        }

        return $this;
    }

    /**
     * Check if an item with the given key/value exists.
     *
     * @param  string  $key  The property key to match
     * @param  mixed  $value  The value to match
     */
    public function hasItem(string $key, mixed $value): bool
    {
        return $this->findItem($key, $value) !== null;
    }

    /**
     * Remove an item by a key/value match.
     *
     * @param  string  $key  The property key to match
     * @param  mixed  $value  The value to match
     */
    public function removeItem(string $key, mixed $value): static
    {
        $index = $this->findItem($key, $value);
        if ($index !== null) {
            array_splice($this->items, $index, 1);
        }

        return $this;
    }

    /**
     * Remove an item from the array.
     *
     * @param  mixed  $itemOrKey  The item to remove (value or index) or the key to check
     * @param  mixed  $value  The value to check if removing by key/value pair
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
     * Remove an item or throw an exception if not found.
     * Follows Laravel's OrFail convention.
     *
     * @param  mixed  $itemOrKey  The item to remove (value or index) or the key to check
     * @param  mixed  $value  The value to check if removing by key/value pair
     *
     * @throws \OutOfBoundsException  If the item is not found
     */
    public function removeOrFail(mixed $itemOrKey, mixed $value = null): static
    {
        // Check if item exists before removal
        if ($value !== null && is_string($itemOrKey)) {
            // Key/value pair removal - check if any match exists
            $found = false;
            foreach ($this->items as $item) {
                if ($item instanceof DataModelContract && isset($item[$itemOrKey]) && $item[$itemOrKey] === $value) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \OutOfBoundsException("No item found with {$itemOrKey} = {$value}");
            }
        } elseif (is_int($itemOrKey) || is_string($itemOrKey)) {
            // Index removal
            if (!isset($this->items[$itemOrKey])) {
                throw new \OutOfBoundsException("Item not found at index: {$itemOrKey}");
            }
        } else {
            // Object instance removal
            $index = array_search($itemOrKey, $this->items, true);
            if ($index === false) {
                throw new \OutOfBoundsException('Item instance not found in array');
            }
        }

        return $this->remove($itemOrKey, $value);
    }

    /**
     * Get the first item.
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
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Get all items as an array.
     *
     * @return DataModelContract[]
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Clear all items.
     */
    public function clear(): static
    {
        $this->items = [];

        return $this;
    }

    /**
     * Filter items using a callback.
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
