<?php

namespace LarAgent\Core\Abstractions;

use ArrayAccess;
use BackedEnum;
use JsonSerializable;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Core\Traits\UsesCachedReflection;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

abstract class DataModel implements ArrayAccess, DataModelContract, JsonSerializable
{
    use UsesCachedReflection;

    /**
     * Convert the model to an array.
     */
    public function toArray(): array
    {
        $result = [];
        $config = static::getCachedConfig();

        foreach ($config['properties'] as $name => $propConfig) {
            /** @var ReflectionProperty $reflection */
            $reflection = $propConfig['reflection'];

            if (! $reflection->isInitialized($this)) {
                continue;
            }

            $value = $this->{$name};

            if ($value instanceof DataModelContract) {
                $result[$name] = $value->toArray();
            } elseif (is_array($value)) {
                $result[$name] = array_map(function ($item) {
                    return $item instanceof DataModelContract ? $item->toArray() : $item;
                }, $value);
            } elseif ($value instanceof UnitEnum) {
                $result[$name] = $value instanceof BackedEnum ? $value->value : $value->name;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Generate the OpenAPI schema for the model.
     */
    public function toSchema(): array
    {
        return static::generateSchemaFromTrait();
    }

    /**
     * Generate the OpenAPI schema for the model statically (public wrapper).
     */
    public static function generateSchema(): array
    {
        return static::generateSchemaFromTrait();
    }

    /**
     * Fill the model with an array of attributes.
     */
    public function fill(array $attributes): static
    {
        $config = static::getCachedConfig();

        foreach ($attributes as $key => $value) {
            if (! isset($config['properties'][$key])) {
                continue;
            }

            $propConfig = $config['properties'][$key];

            // Skip if property is readonly and already initialized
            /** @var ReflectionProperty $reflection */
            $reflection = $propConfig['reflection'];
            if (method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly() && $reflection->isInitialized($this)) {
                continue;
            }

            $value = static::castValue($value, $propConfig['type']);

            $this->{$key} = $value;
        }

        return $this;
    }

    /**
     * Create a new instance from an array of attributes.
     */
    public static function fromArray(array $attributes): static
    {
        $config = static::getCachedConfig();

        if ($config['constructor']) {
            $args = [];
            foreach ($config['constructor'] as $param) {
                $name = $param['name'];
                if (array_key_exists($name, $attributes)) {
                    $value = static::castValue($attributes[$name], $param['type']);
                    $args[] = $value;
                } else {
                    if ($param['hasDefault']) {
                        $args[] = $param['default'];
                    } elseif ($param['allowsNull']) {
                        $args[] = null;
                    } else {
                        throw new \InvalidArgumentException("Missing required constructor parameter: {$name}");
                    }
                }
            }
            $instance = new static(...$args);
            $instance->fill($attributes);

            return $instance;
        }

        $instance = new static;
        $instance->fill($attributes);

        return $instance;
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset) && isset($this->$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            return;
        }
        $this->fill([$offset => $value]);
    }

    /**
     * Unset an offset.
     *
     * Note: Unsetting a typed property without default value will leave it uninitialized.
     * Accessing it afterwards will throw an Error. The property still exists but isset() returns false.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }

    /**
     * Serialize the object to a value that can be natively JSON encoded.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
