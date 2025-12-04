<?php

namespace LarAgent\Core\Abstractions;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;
use ArrayAccess;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionEnum;
use UnitEnum;
use BackedEnum;

use ReflectionType;

abstract class DataModel implements DataModelContract, ArrayAccess, JsonSerializable
{
    protected static array $reflectionCache = [];

    /**
     * Convert the model to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [];
        $config = static::getCachedConfig();

        foreach ($config['properties'] as $name => $propConfig) {
            /** @var ReflectionProperty $reflection */
            $reflection = $propConfig['reflection'];
            
            if (!$reflection->isInitialized($this)) {
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
     *
     * @return array
     */
    public function toSchema(): array
    {
        return static::generateSchema();
    }

    /**
     * Generate the OpenAPI schema for the model statically.
     *
     * @return array
     */
    public static function generateSchema(): array
    {
        $config = static::getCachedConfig();
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => $config['required'],
        ];

        foreach ($config['properties'] as $name => $propConfig) {
            // Skip properties marked with #[ExcludeFromSchema]
            if ($propConfig['excludeFromSchema'] ?? false) {
                continue;
            }

            $propertySchema = static::getPropertySchemaFromConfig($propConfig);
            if ($propertySchema) {
                $schema['properties'][$name] = $propertySchema;
            }
        }

        // Also remove excluded properties from required
        $schema['required'] = array_values(array_filter(
            $schema['required'],
            fn($name) => !($config['properties'][$name]['excludeFromSchema'] ?? false)
        ));
        
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
        $config = static::getCachedConfig();
        
        foreach ($attributes as $key => $value) {
            if (!isset($config['properties'][$key])) {
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
     *
     * @param array $attributes
     * @return static
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

    protected static function getCachedConfig(): array
    {
        $class = static::class;
        if (isset(static::$reflectionCache[$class])) {
            return static::$reflectionCache[$class];
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        
        $config = [
            'properties' => [],
            'required' => [],
            'constructor' => null,
        ];

        foreach ($properties as $property) {
            $name = $property->getName();
            $type = $property->getType();
            
            // Determine if required
            if ($type && !$type->allowsNull() && !$property->hasDefaultValue()) {
                $config['required'][] = $name;
            }

            $descAttributes = $property->getAttributes(Desc::class);
            $description = !empty($descAttributes) ? $descAttributes[0]->newInstance()->description : null;

            $excludeAttributes = $property->getAttributes(ExcludeFromSchema::class);

            $config['properties'][$name] = [
                'reflection' => $property,
                'type' => $type,
                'description' => $description,
                'excludeFromSchema' => !empty($excludeAttributes),
            ];
        }

        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->getNumberOfParameters() > 0) {
            $config['constructor'] = [];
            foreach ($constructor->getParameters() as $param) {
                $config['constructor'][] = [
                    'name' => $param->getName(),
                    'type' => $param->getType(),
                    'allowsNull' => $param->allowsNull(),
                    'hasDefault' => $param->isDefaultValueAvailable(),
                    'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
        }

        static::$reflectionCache[$class] = $config;
        return $config;
    }

    protected static function getPropertySchemaFromConfig(array $propConfig): array
    {
        $schema = static::getTypeSchemaFromType($propConfig['type']);

        if ($propConfig['description']) {
            $schema['description'] = $propConfig['description'];
        }

        return $schema;
    }

    protected static function getTypeSchemaFromType(?ReflectionType $type): array
    {
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
                if (method_exists($typeName, 'generateSchema')) {
                    return $typeName::generateSchema();
                }
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

    /**
     * Unset an offset.
     *
     * Note: Unsetting a typed property without default value will leave it uninitialized.
     * Accessing it afterwards will throw an Error. The property still exists but isset() returns false.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }

    /**
     * Serialize the object to a value that can be natively JSON encoded.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
