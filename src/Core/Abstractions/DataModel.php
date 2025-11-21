<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Attributes\Desc;
use ArrayAccess;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionEnum;
use UnitEnum;
use BackedEnum;

use ReflectionType;

abstract class DataModel implements DataModelContract, ArrayAccess
{
    /**
     * Convert the model to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if (!$property->isInitialized($this)) {
                continue;
            }
            
            $value = $property->getValue($this);
            $key = $property->getName();

            if ($value instanceof DataModelContract) {
                $result[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $result[$key] = array_map(function ($item) {
                    return $item instanceof DataModelContract ? $item->toArray() : $item;
                }, $value);
            } elseif ($value instanceof UnitEnum) {
                $result[$key] = $value instanceof BackedEnum ? $value->value : $value->name;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Generate the OpenAPI schema for the model.
     *
     * @return array
     */
    public function toSchema(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name = $property->getName();
            $type = $property->getType();
            
            // Determine if required
            if ($type && !$type->allowsNull() && !$property->hasDefaultValue()) {
                $schema['required'][] = $name;
            }

            $propertySchema = $this->getPropertySchema($property);
            if ($propertySchema) {
                $schema['properties'][$name] = $propertySchema;
            }
        }
        
        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        $reflection = new ReflectionClass($this);
        
        foreach ($attributes as $key => $value) {
            if (!$reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);
            
            if (!$property->isPublic()) {
                continue;
            }

            $value = static::castValue($value, $property->getType());
            
            $this->{$key} = $value;
        }

        return $this;
    }

    /**
     * Create a new instance from an array of attributes.
     *
     * @param array $attributes
     * @return static
     */
    public static function fromArray(array $attributes): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor && $constructor->getNumberOfParameters() > 0) {
            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $attributes)) {
                    $value = static::castValue($attributes[$name], $param->getType());
                    $args[] = $value;
                } else {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } elseif ($param->allowsNull()) {
                        $args[] = null;
                    } else {
                        // Missing required parameter
                        throw new \InvalidArgumentException("Missing required constructor parameter: {$name}");
                    }
                }
            }
            $instance = $reflection->newInstanceArgs($args);
            $instance->fill($attributes);
            return $instance;
        }

        $instance = new static();
        $instance->fill($attributes);
        return $instance;
    }

    protected static function castValue(mixed $value, ?ReflectionType $type): mixed
    {
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            
            if (is_subclass_of($typeName, DataModelContract::class) && is_array($value)) {
                return $typeName::fromArray($value);
            } elseif (enum_exists($typeName)) {
                if ($value instanceof $typeName) {
                    return $value;
                } elseif (is_subclass_of($typeName, BackedEnum::class)) {
                    return $typeName::tryFrom($value) ?? $value;
                } elseif (is_subclass_of($typeName, UnitEnum::class)) {
                    foreach ($typeName::cases() as $case) {
                        if ($case->name === $value) {
                            return $case;
                        }
                    }
                }
            }
        }
        return $value;
    }

    protected function getPropertySchema(ReflectionProperty $property): array
    {
        $schema = $this->getTypeSchema($property);

        $attributes = $property->getAttributes(Desc::class);
        if (!empty($attributes)) {
            $schema['description'] = $attributes[0]->newInstance()->description;
        }

        return $schema;
    }

    protected function getTypeSchema(ReflectionProperty $property): array
    {
        $type = $property->getType();
        
        if (!$type) {
            return ['type' => 'string'];
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            if ($type->isBuiltin()) {
                return match ($typeName) {
                    'int' => ['type' => 'integer'],
                    'float' => ['type' => 'number'],
                    'bool' => ['type' => 'boolean'],
                    'array' => ['type' => 'array'],
                    default => ['type' => 'string'],
                };
            }

            if (is_subclass_of($typeName, DataModelContract::class)) {
                try {
                    $instance = (new ReflectionClass($typeName))->newInstanceWithoutConstructor();
                    return $instance->toSchema();
                } catch (\Throwable $e) {
                    return ['type' => 'object'];
                }
            }

            if (enum_exists($typeName)) {
                $reflectionEnum = new ReflectionEnum($typeName);
                $cases = $reflectionEnum->getCases();
                $values = [];
                $schemaType = 'string';
                
                if ($reflectionEnum->isBacked()) {
                    $backingType = $reflectionEnum->getBackingType()->getName();
                    $schemaType = $backingType === 'int' ? 'integer' : 'string';
                    $values = array_map(fn($case) => $case->getBackingValue(), $cases);
                } else {
                    $values = array_map(fn($case) => $case->getName(), $cases);
                }

                return [
                    'type' => $schemaType,
                    'enum' => $values,
                ];
            }
        }

        return ['type' => 'string'];
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

    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }
}
