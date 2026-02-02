# Attribute-Based Storage Registration

## Overview

Simplified storage registration using **PHP 8 Attributes**, following the existing `chatHistory()` pattern.

## Syntax

### Basic Usage
```php
class MyAgent extends Agent
{
    #[Storage]
    protected $preferences = PreferencesArray::class;
    
    #[Storage(['add', 'read', 'all'])]
    protected $memories = MemoriesArray::class;
    
    // Access via magic method - returns Storage instance
    // $this->preferences()->add($item);
    // $this->memories()->save();
}
```

### Advanced Configuration
```php
#[Storage(
    tools: ['add', 'read', 'remove', 'all', 'clear'],
    toolPrefix: 'user',           // Results in: user_add, user_read, etc.
    description: 'User preferences'
)]
protected $preferences = PreferencesArray::class;
```

## Key Principles Alignment

✅ **Ease of use**: Single line declaration  
✅ **Flexible**: Attribute parameters allow customization  
✅ **Ease of extension**: Follows existing `chatHistory()` pattern  
✅ **Replacability**: Uses Storage/Driver architecture  
✅ **Standardization**: Consistent with `#[Tool]`, `#[Desc]` attributes

---

## Implementation Steps

### Step 1: Add `getRegistrationKey()` to Storage Contract

**File**: `src/Context/Contracts/Storage.php`

Add method to interface:
```php
/**
 * Get the key used for Context registration.
 * Defaults to identity scope for proper isolation.
 */
public function getRegistrationKey(): string;
```

### Step 2: Implement `getRegistrationKey()` in Abstract Storage

**File**: `src/Context/Abstract/Storage.php`

Add implementation:
```php
/**
 * Get the key used for Context registration.
 */
public function getRegistrationKey(): string
{
    return $this->identity->getScope();
}
```

### Step 3: Update Context to Use Registration Key

**File**: `src/Context/Context.php`

Modify `register()` method to use `getRegistrationKey()` instead of `getStoragePrefix()`:

```php
public function register(StorageContract $storage): static
{
    $prefix = $storage->getRegistrationKey();
    $this->storages[$prefix] = $storage;
    $this->identityStorage->addIdentity($storage->getIdentity());

    $this->dispatchEvent(new StorageRegistered($this, $prefix, $storage));

    return $this;
}
```

This is backward-compatible because existing storages set `$this->identity = $identity->withScope($this->getStoragePrefix())` in constructor, so `getRegistrationKey()` returns the same value as `getStoragePrefix()`.

### Step 4: Create Storage Attribute

**File**: `src/Attributes/Storage.php` (NEW)

```php
<?php

namespace LarAgent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Storage
{
    /**
     * @param array<string>|null $tools Tools to generate: 'add', 'remove', 'read', 'all', 'clear'
     * @param string|null $toolPrefix Custom prefix for tool names (default: property name)
     * @param string|null $description Description for auto-generated tools
     * @param array|string|null $drivers Custom storage drivers
     */
    public function __construct(
        public readonly ?array $tools = null,
        public readonly ?string $toolPrefix = null,
        public readonly ?string $description = null,
        public readonly array|string|null $drivers = null,
    ) {}
    
    public function hasTools(): bool
    {
        return $this->tools !== null && count($this->tools) > 0;
    }
    
    public function getTools(): array
    {
        return $this->tools ?? [];
    }
}
```

### Step 5: Create DynamicStorage Class

**File**: `src/Context/Storages/DynamicStorage.php` (NEW)

```php
<?php

namespace LarAgent\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Context\StorageManager;

class DynamicStorage extends Storage
{
    protected string $instancePrefix;
    protected string $dataModelArrayClass;
    
    public function __construct(
        SessionIdentityContract $identity,
        array|string|null $driversConfig,
        string $prefix,
        string $dataModelArrayClass
    ) {
        $this->instancePrefix = $prefix;
        $this->dataModelArrayClass = $dataModelArrayClass;
        
        // Set identity with instance prefix
        $this->identity = $identity->withScope($prefix);
        
        // Setup driver
        if ($driversConfig === null) {
            $driversConfig = $this->defaultDrivers;
        } elseif (!is_array($driversConfig)) {
            $driversConfig = [$driversConfig];
        }
        
        $this->storageManager = new StorageManager($driversConfig);
        $this->resetItems();
    }
    
    protected function getDataModelClass(): string
    {
        return $this->dataModelArrayClass;
    }
    
    public static function getStoragePrefix(): string
    {
        return 'dynamic';
    }
    
    public function getRegistrationKey(): string
    {
        return $this->instancePrefix;
    }
}
```

### Step 6: Create DiscoverStorageProperties Trait

**File**: `src/Core/Traits/DiscoverStorageProperties.php` (NEW)

```php
<?php

namespace LarAgent\Core\Traits;

use LarAgent\Attributes\Storage as StorageAttribute;
use LarAgent\Core\Contracts\DataModelArray;
use ReflectionClass;

trait DiscoverStorageProperties
{
    protected static array $storagePropertiesCache = [];
    
    protected function discoverStorageProperties(): array
    {
        $class = static::class;
        
        if (isset(static::$storagePropertiesCache[$class])) {
            return static::$storagePropertiesCache[$class];
        }
        
        $discovered = [];
        $reflection = new ReflectionClass($this);
        
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(StorageAttribute::class);
            
            if (empty($attributes)) {
                continue;
            }
            
            $attribute = $attributes[0]->newInstance();
            $propertyName = $property->getName();
            
            $property->setAccessible(true);
            $dataModelClass = $property->getValue($this);
            
            if (!is_string($dataModelClass)) {
                throw new \InvalidArgumentException(
                    "Storage property '{$propertyName}' must have a DataModelArray class name as default value"
                );
            }
            
            if (!class_exists($dataModelClass)) {
                throw new \InvalidArgumentException(
                    "Storage property '{$propertyName}' references non-existent class '{$dataModelClass}'"
                );
            }
            
            if (!is_subclass_of($dataModelClass, DataModelArray::class)) {
                throw new \InvalidArgumentException(
                    "Storage property '{$propertyName}' class must implement DataModelArray"
                );
            }
            
            $discovered[$propertyName] = [
                'attribute' => $attribute,
                'dataModelClass' => $dataModelClass,
            ];
        }
        
        static::$storagePropertiesCache[$class] = $discovered;
        
        return $discovered;
    }
    
    protected function getStoragePropertyNames(): array
    {
        return array_keys($this->discoverStorageProperties());
    }
    
    public static function clearStoragePropertiesCache(): void
    {
        static::$storagePropertiesCache = [];
    }
}
```

### Step 7: Create HasAttributeStorages Trait

**File**: `src/Core/Traits/HasAttributeStorages.php` (NEW)

```php
<?php

namespace LarAgent\Core\Traits;

use LarAgent\Attributes\Storage as StorageAttribute;
use LarAgent\Context\Contracts\Storage as StorageContract;
use LarAgent\Context\Storages\DynamicStorage;

trait HasAttributeStorages
{
    use DiscoverStorageProperties;
    
    protected array $attributeStorages = [];
    
    protected function setupAttributeStorages(): void
    {
        $storageProperties = $this->discoverStorageProperties();
        
        foreach ($storageProperties as $propertyName => $config) {
            $attribute = $config['attribute'];
            $dataModelArrayClass = $config['dataModelClass'];
            
            $drivers = $attribute->drivers ?? $this->defaultStorageDrivers();
            if (!is_array($drivers)) {
                $drivers = [$drivers];
            }
            
            $storage = new DynamicStorage(
                $this->context()->getIdentity(),
                $drivers,
                $propertyName,
                $dataModelArrayClass
            );
            
            $this->context()->register($storage);
            $this->attributeStorages[$propertyName] = $storage;
            
            $this->callEvent('onStorageRegistered', [$propertyName, $storage, $attribute]);
        }
    }
    
    public function __call(string $method, array $arguments): mixed
    {
        if (isset($this->attributeStorages[$method])) {
            return $this->attributeStorages[$method];
        }
        
        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist.', static::class, $method)
        );
    }
    
    public function getAttributeStorage(string $name): ?StorageContract
    {
        return $this->attributeStorages[$name] ?? null;
    }
    
    public function hasAttributeStorage(string $name): bool
    {
        return isset($this->attributeStorages[$name]);
    }
    
    public function getAttributeStorageNames(): array
    {
        return array_keys($this->attributeStorages);
    }
    
    protected function buildAttributeStorageTools(): array
    {
        $tools = [];
        $storageProperties = $this->discoverStorageProperties();
        
        foreach ($storageProperties as $propertyName => $config) {
            /** @var StorageAttribute $attribute */
            $attribute = $config['attribute'];
            
            if (!$attribute->hasTools()) {
                continue;
            }
            
            $dataModelArrayClass = $config['dataModelClass'];
            $allowedModels = $dataModelArrayClass::allowedModels();
            
            $dataModelClass = null;
            if (count($allowedModels) === 1 && array_is_list($allowedModels)) {
                $dataModelClass = $allowedModels[0];
            }
            
            $toolPrefix = $attribute->toolPrefix ?? $propertyName;
            $description = $attribute->description;
            
            foreach ($attribute->getTools() as $toolType) {
                $tool = match ($toolType) {
                    'add' => $this->createStorageAddTool($propertyName, $toolPrefix, $dataModelClass, $description),
                    'remove' => $this->createStorageRemoveTool($propertyName, $toolPrefix, $description),
                    'read', 'get' => $this->createStorageReadTool($propertyName, $toolPrefix, $description),
                    'all', 'list' => $this->createStorageAllTool($propertyName, $toolPrefix, $description),
                    'clear' => $this->createStorageClearTool($propertyName, $toolPrefix, $description),
                    default => null,
                };
                
                if ($tool !== null) {
                    $tools[] = $tool;
                }
            }
        }
        
        return $tools;
    }
    
    protected function createStorageAddTool(
        string $storageName,
        string $toolPrefix,
        ?string $dataModelClass,
        ?string $description
    ): \LarAgent\Tool {
        $singularName = \Illuminate\Support\Str::singular($storageName);
        $toolName = "add_{$toolPrefix}";
        
        $tool = \LarAgent\Tool::create($toolName, $description ?? "Add a new {$singularName}");
        
        if ($dataModelClass && method_exists($dataModelClass, 'generateSchema')) {
            $schema = $dataModelClass::generateSchema();
            
            if (isset($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propSchema) {
                    $type = $propSchema['type'] ?? 'string';
                    $propDesc = $propSchema['description'] ?? "The {$propName}";
                    $required = in_array($propName, $schema['required'] ?? []);
                    
                    $tool->addProperty($propName, $type, $propDesc, $required);
                }
            }
        }
        
        $agent = $this;
        $tool->setCallback(function (array $data) use ($agent, $storageName, $dataModelClass) {
            $storage = $agent->getAttributeStorage($storageName);
            
            $item = $dataModelClass ? $dataModelClass::fromArray($data) : $data;
            
            $storage->add($item);
            $storage->save();
            
            return [
                'success' => true,
                'message' => "Added to {$storageName}",
                'count' => $storage->count()
            ];
        });
        
        return $tool;
    }
    
    protected function createStorageRemoveTool(
        string $storageName,
        string $toolPrefix,
        ?string $description
    ): \LarAgent\Tool {
        $singularName = \Illuminate\Support\Str::singular($storageName);
        $agent = $this;
        
        return \LarAgent\Tool::create("remove_{$toolPrefix}", $description ?? "Remove a {$singularName}")
            ->addProperty('index', 'integer', "Index of the {$singularName} to remove", true)
            ->setCallback(function (int $index) use ($agent, $storageName) {
                $storage = $agent->getAttributeStorage($storageName);
                $storage->get()->remove($index);
                $storage->save();
                
                return ['success' => true, 'message' => "Removed item at index {$index}"];
            });
    }
    
    protected function createStorageReadTool(
        string $storageName,
        string $toolPrefix,
        ?string $description
    ): \LarAgent\Tool {
        $singularName = \Illuminate\Support\Str::singular($storageName);
        $agent = $this;
        
        return \LarAgent\Tool::create("get_{$toolPrefix}", $description ?? "Get a specific {$singularName}")
            ->addProperty('index', 'integer', "Index of the {$singularName} to retrieve", true)
            ->setCallback(function (int $index) use ($agent, $storageName) {
                $storage = $agent->getAttributeStorage($storageName);
                $items = $storage->get();
                $item = $items[$index] ?? null;
                
                if ($item === null) {
                    return ['success' => false, 'message' => "No item at index {$index}"];
                }
                
                return [
                    'success' => true,
                    'item' => $item instanceof \LarAgent\Core\Contracts\DataModel 
                        ? $item->toArray() 
                        : $item
                ];
            });
    }
    
    protected function createStorageAllTool(
        string $storageName,
        string $toolPrefix,
        ?string $description
    ): \LarAgent\Tool {
        $agent = $this;
        
        return \LarAgent\Tool::create("list_{$toolPrefix}", $description ?? "List all items in {$storageName}")
            ->setCallback(function () use ($agent, $storageName) {
                $storage = $agent->getAttributeStorage($storageName);
                
                return [
                    'success' => true,
                    'count' => $storage->count(),
                    'items' => $storage->get()->toArray()
                ];
            });
    }
    
    protected function createStorageClearTool(
        string $storageName,
        string $toolPrefix,
        ?string $description
    ): \LarAgent\Tool {
        $agent = $this;
        
        return \LarAgent\Tool::create("clear_{$toolPrefix}", $description ?? "Clear all items from {$storageName}")
            ->setCallback(function () use ($agent, $storageName) {
                $storage = $agent->getAttributeStorage($storageName);
                $storage->clear();
                $storage->save();
                
                return ['success' => true, 'message' => "Cleared all items from {$storageName}"];
            });
    }
}
```

### Step 8: Modify Agent Class

**File**: `src/Agent.php`

1. Add trait import:
```php
use LarAgent\Core\Traits\HasAttributeStorages;
```

2. Add trait to class:
```php
class Agent
{
    use Configs;
    use Events;
    use HasContext;
    use HasAttributeStorages;  // NEW
    use UsesCachedReflection;
    use UsesLogger;
```

3. Call `setupAttributeStorages()` in constructor after context setup:
```php
$this->setupContext($defaultStorageDrivers);

$this->setupAttributeStorages();  // NEW - after context, before chatHistory

if ($this->forceReadContext) {
    $this->readContext();
}
```

4. Update `getTools()` to include attribute storage tools:
```php
public function getTools(): array
{
    $classTools = array_map(function ($tool) {
        if (is_string($tool) && class_exists($tool)) {
            return new $tool;
        }
        return $tool;
    }, $this->tools);

    $registeredTools = $this->registerTools();
    $attributeTools = $this->buildToolsFromAttributeMethods();
    $mcpTools = $this->buildToolsFromMcpServers();
    $attributeStorageTools = $this->buildAttributeStorageTools();  // NEW

    return array_merge(
        $classTools, 
        $registeredTools, 
        $attributeTools, 
        $mcpTools,
        $attributeStorageTools  // NEW
    );
}
```

### Step 9: Create Artisan Command

**File**: `src/Commands/MakeStorageModelCommand.php` (NEW)

```php
<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeStorageModelCommand extends Command
{
    protected $signature = 'laragent:storage-model {name : The name of the DataModelArray class}
                            {--model= : The DataModel class to store}
                            {--properties=* : Properties in format name:type}';
    
    protected $description = 'Create a new DataModelArray class for use with #[Storage] attribute';
    
    public function handle()
    {
        // Generate both DataModel and DataModelArray classes
        // based on provided properties
    }
}
```

---

## Usage Examples

### Basic Usage
```php
use LarAgent\Agent;
use LarAgent\Attributes\Storage;
use App\DataModels\PreferencesArray;

class PersonalAssistant extends Agent
{
    #[Storage]
    protected $preferences = PreferencesArray::class;
    
    #[Storage(['add', 'all'])]
    protected $memories = MemoriesArray::class;
    
    public function someMethod()
    {
        // Add item
        $this->preferences()->add(new Preference('theme', 'dark'));
        $this->preferences()->save();
        
        // Access DataModelArray
        $allPrefs = $this->preferences()->get();
        
        // Count
        $count = $this->preferences()->count();
        
        // Clear
        $this->memories()->clear();
        $this->memories()->save();
    }
}
```

### DataModel + DataModelArray Pattern
```php
// app/DataModels/Preference.php
class Preference extends DataModel
{
    public string $key;
    public string $value;
    public ?string $category = null;
}

// app/DataModels/PreferencesArray.php  
class PreferencesArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [Preference::class];
    }
}
```

### IDE Support (PHPDoc)
```php
/**
 * @method \LarAgent\Context\Contracts\Storage preferences()
 * @method \LarAgent\Context\Contracts\Storage memories()
 */
class MyAgent extends Agent
{
    #[Storage]
    protected $preferences = PreferencesArray::class;
}
```

---

## Implementation Order

1. ⬜ Add `getRegistrationKey()` to `StorageContract` interface
2. ⬜ Implement `getRegistrationKey()` in `Abstract\Storage`
3. ⬜ Update `Context::register()` to use `getRegistrationKey()`
4. ⬜ Create `#[Storage]` attribute (`src/Attributes/Storage.php`)
5. ⬜ Create `DynamicStorage` class (`src/Context/Storages/DynamicStorage.php`)
6. ⬜ Create `DiscoverStorageProperties` trait
7. ⬜ Create `HasAttributeStorages` trait with tool generation
8. ⬜ Add `HasAttributeStorages` trait to Agent class
9. ⬜ Call `setupAttributeStorages()` in Agent constructor
10. ⬜ Update `getTools()` to include storage tools
11. ⬜ Create artisan command for DataModelArray generation
12. ⬜ Write unit tests
13. ⬜ Write manual tests

---

## Testing Requirements

### Unit Tests
- Storage attribute parsing and validation
- DynamicStorage construction and operations
- Property discovery with reflection caching
- Magic method access (`__call`)
- Tool generation for each type (add, remove, read, all, clear)
- Tool callback execution

### Manual Tests
- LLM integration with generated tools
- Persistence across requests
- Multiple storage properties
- Different storage drivers

---

## Breaking Changes

**None.** The `getRegistrationKey()` addition is backward-compatible:
- Existing storages: `getRegistrationKey()` returns `identity->getScope()` which equals `getStoragePrefix()`
- New `DynamicStorage`: `getRegistrationKey()` returns instance-specific prefix
- All existing code continues to work unchanged
