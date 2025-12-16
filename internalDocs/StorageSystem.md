# Storage System and DataModel Role

The Storage system in LarAgent provides a unified abstraction for persisting data across different backends. DataModel serves as the foundation for typed, structured data handling within storages.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                         Context                              │
│  (Orchestrates multiple storages for an agent)              │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Storage (Abstract)                        │
│  - ChatHistoryStorage                                        │
│  - UsageStorage                                              │
│  - IdentityStorage                                           │
│  - Your Custom Storage                                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    StorageManager                            │
│  (Manages driver chain: primary + fallbacks)                 │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                  StorageDriver (Abstract)                    │
│  - CacheStorage                                              │
│  - FileStorage                                               │
│  - EloquentStorage                                           │
│  - SessionStorage                                            │
│  - InMemoryStorage                                           │
│  - Your Custom Driver                                        │
└─────────────────────────────────────────────────────────────┘
```

## Storage Components

### 1. Storage (Abstract Base Class)

The `Storage` abstract class provides:

- **Identity-based key generation**: Each storage uses `SessionIdentity` for unique keys
- **Lazy loading**: Data is loaded from drivers only when first accessed
- **Dirty tracking**: Only writes to drivers when data has changed
- **DataModelArray integration**: Stores typed collections of DataModels

```php
namespace LarAgent\Context\Abstract;

abstract class Storage implements StorageContract
{
    protected StorageManager $storageManager;
    protected SessionIdentityContract $identity;
    protected DataModelArray $items;
    protected bool $dirty = false;
    protected bool $loaded = false;

    // Each storage defines its data model type
    abstract protected function getDataModelClass(): string;
    
    // Each storage defines its prefix for key isolation
    abstract public static function getStoragePrefix(): string;
}
```

### 2. StorageManager

Manages a chain of storage drivers with primary/fallback pattern:

```php
// Configuration
$drivers = [
    CacheStorage::class,    // Primary - read first, write first
    FileStorage::class,     // Fallback - read if primary fails
];

$manager = new StorageManager($drivers);

// Read: tries primary first, then fallbacks
$data = $manager->read($identity);

// Write: writes to all drivers
$manager->save($identity, $data);

// Remove: removes from all drivers
$manager->remove($identity);
```

### 3. StorageDriver (Abstract)

Interface for persistence backends:

```php
interface StorageDriver
{
    // Read data from memory/storage
    public function readFromMemory(SessionIdentity $identity): ?array;
    
    // Write data to memory/storage
    public function writeToMemory(SessionIdentity $identity, array $data): bool;
    
    // Remove data from memory/storage
    public function removeFromMemory(SessionIdentity $identity): bool;
    
    // Factory method for creating instances
    public static function make(?array $config = null): static;
}
```

## Built-in Storage Implementations

### ChatHistoryStorage

Stores conversation messages:

```php
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Messages\DataModels\MessageArray;

class ChatHistoryStorage extends Storage implements ChatHistoryInterface
{
    protected function getDataModelClass(): string
    {
        return MessageArray::class;  // Collection of Message DataModels
    }
    
    public static function getStoragePrefix(): string
    {
        return 'chatHistory';  // Key prefix for isolation
    }
    
    public function addMessage(MessageInterface $message): void;
    public function getMessages(): MessageArray;
    public function getLastMessage(): ?MessageInterface;
}
```

### UsageStorage

Tracks token usage:

```php
use LarAgent\Usage\UsageStorage;
use LarAgent\Usage\DataModels\UsageArray;

class UsageStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return UsageArray::class;  // Collection of UsageRecord DataModels
    }
    
    public static function getStoragePrefix(): string
    {
        return 'usage';
    }
    
    public function addUsage(Usage $usage): void;
    public function getUsageRecords(): UsageArray;
    public function aggregate(array $filters = []): array;
}
```

### IdentityStorage

Tracks all storage identities for discovery:

```php
use LarAgent\Context\Storages\IdentityStorage;
use LarAgent\Context\DataModels\SessionIdentityArray;

class IdentityStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return SessionIdentityArray::class;  // Collection of SessionIdentity DataModels
    }
    
    public static function getStoragePrefix(): string
    {
        return 'context';
    }
    
    public function addIdentity(SessionIdentityContract $identity): void;
    public function getIdentities(): SessionIdentityArray;
    public function getIdentitiesByScope(string $scope): SessionIdentityArray;
}
```

## DataModel in Storage

### Role of DataModel

DataModel provides:
1. **Type Safety**: Ensure data consistency through typed properties
2. **Serialization**: Convert to/from arrays for storage
3. **Schema Generation**: Auto-generate JSON schemas for validation
4. **Nested Objects**: Support complex, nested data structures

### DataModelArray

Collections of DataModels with polymorphic support:

```php
use LarAgent\Core\Abstractions\DataModelArray;

class MessageArray extends DataModelArray
{
    // Define allowed model types and their discriminator values
    public static function allowedModels(): array
    {
        return [
            'user' => UserMessage::class,
            'assistant' => AssistantMessage::class,
            'system' => SystemMessage::class,
            'tool' => ToolMessage::class,
        ];
    }
    
    // Field used to determine model type
    public function discriminator(): string
    {
        return 'role';
    }
}
```

### Data Flow

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│  DataModel   │────▶│ DataModel    │────▶│    Driver    │
│  Instance    │     │ Array        │     │  (JSON/DB)   │
└──────────────┘     └──────────────┘     └──────────────┘
       │                    │                    │
       │   toArray()        │   toArray()        │
       └────────────────────┴────────────────────┘
                            │
                      array of arrays
                            │
       ┌────────────────────┴────────────────────┐
       │   fromArray()      │   fromArray()      │
       ▼                    ▼                    ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│  DataModel   │◀────│ DataModel    │◀────│    Driver    │
│  Instance    │     │ Array        │     │  (JSON/DB)   │
└──────────────┘     └──────────────┘     └──────────────┘
```

## Built-in Storage Drivers

### CacheStorage

Uses Laravel's cache system:

```php
use LarAgent\Context\Drivers\CacheStorage;

// Default cache store
$driver = new CacheStorage();

// Specific cache store
$driver = new CacheStorage('redis');
```

### FileStorage

Stores as JSON files:

```php
use LarAgent\Context\Drivers\FileStorage;

// Default settings
$driver = new FileStorage();

// Custom disk and folder
$driver = new FileStorage(
    disk: 's3',
    folder: 'ai_conversations'
);
```

### EloquentStorage

Full Eloquent integration with separate rows per item:

```php
use LarAgent\Context\Drivers\EloquentStorage;

// Default LaragentMessage model
$driver = new EloquentStorage();

// Custom model
$driver = new EloquentStorage(\App\Models\ChatMessage::class);

// Configure columns
$driver = new EloquentStorage();
$driver->setKeyColumn('session_key');
$driver->setPositionColumn('position');
```

### SimpleEloquentStorage

Stores entire array as JSON:

```php
use LarAgent\Context\Drivers\SimpleEloquentStorage;

// Default LaragentStorage model
$driver = new SimpleEloquentStorage();

// Custom model
$driver = new SimpleEloquentStorage(\App\Models\StorageRecord::class);
```

### SessionStorage

Uses PHP session:

```php
use LarAgent\Context\Drivers\SessionStorage;

$driver = new SessionStorage();
```

### InMemoryStorage

No persistence (for testing/temporary use):

```php
use LarAgent\Context\Drivers\InMemoryStorage;

$driver = new InMemoryStorage();
```

## Creating Custom DataModels for Storage

### Simple DataModel

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class CustomerNote extends DataModel
{
    #[Desc('The note content')]
    public string $content;
    
    #[Desc('When the note was created')]
    public string $createdAt;
    
    #[Desc('Who created the note')]
    public ?string $createdBy = null;
}
```

### DataModelArray for Collection

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class CustomerNoteArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'default' => CustomerNote::class,
        ];
    }
    
    public function discriminator(): string
    {
        return 'type';  // Or any field, default is 'default'
    }
    
    // Optional: Custom filtering
    public function filterByCreator(string $creator): static
    {
        return $this->filter(fn($note) => $note->createdBy === $creator);
    }
}
```

### Using DataModel in Custom Storage

```php
<?php

namespace App\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use App\DataModels\CustomerNoteArray;

class CustomerNotesStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return CustomerNoteArray::class;
    }
    
    public static function getStoragePrefix(): string
    {
        return 'customerNotes';
    }
    
    public function addNote(string $content, ?string $createdBy = null): void
    {
        $note = CustomerNote::fromArray([
            'content' => $content,
            'createdAt' => now()->toIso8601String(),
            'createdBy' => $createdBy,
        ]);
        
        $this->add($note);
    }
    
    public function getNotes(): CustomerNoteArray
    {
        return $this->get();
    }
    
    public function getNotesByCreator(string $creator): CustomerNoteArray
    {
        return $this->getNotes()->filterByCreator($creator);
    }
}
```

## Key Isolation and Scoping

### How Keys Are Generated

```php
// SessionIdentity generates keys as:
// {scope}_{group|agentName}_{userId|chatName|'default'}

// Example identities and their keys:
$identity1 = new SessionIdentity(
    agentName: 'SupportAgent',
    chatName: 'session-123',
    scope: 'chatHistory'
);
// Key: chatHistory_SupportAgent_session-123

$identity2 = new SessionIdentity(
    agentName: 'SupportAgent',
    userId: 'user-456',
    scope: 'usage'
);
// Key: usage_SupportAgent_user-456

$identity3 = new SessionIdentity(
    agentName: 'SupportAgent',
    userId: 'user-456',
    group: 'premium',
    scope: 'chatHistory'
);
// Key: chatHistory_premium_user-456
```

### Scope Application

Storages automatically apply their scope to identities:

```php
// In Storage constructor
$this->identity = $identity->withScope($this->getStoragePrefix());

// This ensures different storage types never collide:
// ChatHistoryStorage: chatHistory_SupportAgent_user-123
// UsageStorage: usage_SupportAgent_user-123
// CustomerNotesStorage: customerNotes_SupportAgent_user-123
```

## Real-World Scenario: Customer Notes System

### Requirements
- Store customer notes per agent session
- Support multiple note types (text, action, reminder)
- Filter notes by type and creator
- Persist to database

### Implementation

#### 1. Define DataModels

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class CustomerNote extends DataModel
{
    #[Desc('Type of note: text, action, reminder')]
    public string $type = 'text';
    
    #[Desc('The note content')]
    public string $content;
    
    #[Desc('ISO 8601 timestamp')]
    public string $createdAt;
    
    #[Desc('User who created the note')]
    public ?string $createdBy = null;
    
    #[Desc('Priority level: low, medium, high')]
    public string $priority = 'medium';
    
    #[Desc('Whether the note is resolved')]
    public bool $resolved = false;
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class CustomerNoteArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'text' => CustomerNote::class,
            'action' => CustomerNote::class,
            'reminder' => CustomerNote::class,
        ];
    }
    
    public function discriminator(): string
    {
        return 'type';
    }
    
    // Filter by type
    public function ofType(string $type): static
    {
        return $this->filter(fn($note) => $note->type === $type);
    }
    
    // Filter by priority
    public function highPriority(): static
    {
        return $this->filter(fn($note) => $note->priority === 'high');
    }
    
    // Filter unresolved
    public function unresolved(): static
    {
        return $this->filter(fn($note) => !$note->resolved);
    }
    
    // Aggregate by type
    public function countByType(): array
    {
        $counts = [];
        foreach ($this->items as $note) {
            $type = $note->type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }
}
```

#### 2. Create Storage

```php
<?php

namespace App\Context\Storages;

use LarAgent\Context\Abstract\Storage;
use App\DataModels\CustomerNote;
use App\DataModels\CustomerNoteArray;

class CustomerNotesStorage extends Storage
{
    protected function getDataModelClass(): string
    {
        return CustomerNoteArray::class;
    }
    
    public static function getStoragePrefix(): string
    {
        return 'customerNotes';
    }
    
    /**
     * Add a text note.
     */
    public function addNote(string $content, ?string $createdBy = null): CustomerNote
    {
        $note = CustomerNote::fromArray([
            'type' => 'text',
            'content' => $content,
            'createdAt' => now()->toIso8601String(),
            'createdBy' => $createdBy,
        ]);
        
        $this->add($note);
        return $note;
    }
    
    /**
     * Add an action item.
     */
    public function addAction(string $content, string $priority = 'medium', ?string $createdBy = null): CustomerNote
    {
        $note = CustomerNote::fromArray([
            'type' => 'action',
            'content' => $content,
            'createdAt' => now()->toIso8601String(),
            'createdBy' => $createdBy,
            'priority' => $priority,
        ]);
        
        $this->add($note);
        return $note;
    }
    
    /**
     * Add a reminder.
     */
    public function addReminder(string $content, ?string $createdBy = null): CustomerNote
    {
        $note = CustomerNote::fromArray([
            'type' => 'reminder',
            'content' => $content,
            'createdAt' => now()->toIso8601String(),
            'createdBy' => $createdBy,
        ]);
        
        $this->add($note);
        return $note;
    }
    
    /**
     * Get all notes.
     */
    public function getNotes(): CustomerNoteArray
    {
        return $this->get();
    }
    
    /**
     * Get unresolved action items.
     */
    public function getPendingActions(): CustomerNoteArray
    {
        return $this->getNotes()->ofType('action')->unresolved();
    }
    
    /**
     * Get high priority items.
     */
    public function getHighPriority(): CustomerNoteArray
    {
        return $this->getNotes()->highPriority();
    }
}
```

#### 3. Integrate with Agent

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use App\Context\Storages\CustomerNotesStorage;

class CustomerSupportAgent extends Agent
{
    protected $instructions = 'You are a customer support agent.';
    
    protected $history = 'database';
    
    protected CustomerNotesStorage $notesStorage;
    
    protected function setupChatHistory(): void
    {
        parent::setupChatHistory();
        
        // Register notes storage with context
        $this->notesStorage = $this->context()->make(CustomerNotesStorage::class);
    }
    
    /**
     * Get customer notes storage.
     */
    public function notes(): CustomerNotesStorage
    {
        return $this->notesStorage;
    }
    
    /**
     * Tool: Add a note about the customer.
     */
    #[\LarAgent\Attributes\Tool('Add a note about this customer interaction')]
    public function addCustomerNote(
        string $content,
        string $type = 'text',
        string $priority = 'medium'
    ): string {
        $note = match ($type) {
            'action' => $this->notes()->addAction($content, $priority, auth()->id()),
            'reminder' => $this->notes()->addReminder($content, auth()->id()),
            default => $this->notes()->addNote($content, auth()->id()),
        };
        
        return "Note added: {$note->content}";
    }
    
    /**
     * Tool: Get pending actions for this customer.
     */
    #[\LarAgent\Attributes\Tool('Get pending action items for this customer')]
    public function getPendingActions(): string
    {
        $actions = $this->notes()->getPendingActions();
        
        if ($actions->count() === 0) {
            return "No pending actions.";
        }
        
        $list = [];
        foreach ($actions as $action) {
            $list[] = "- [{$action->priority}] {$action->content}";
        }
        
        return "Pending actions:\n" . implode("\n", $list);
    }
}
```

#### 4. Usage in Controller

```php
<?php

namespace App\Http\Controllers;

use App\AiAgents\CustomerSupportAgent;
use Illuminate\Http\Request;

class CustomerSupportController extends Controller
{
    public function chat(Request $request)
    {
        $user = $request->user();
        $customerId = $request->input('customer_id');
        
        $agent = CustomerSupportAgent::for($customerId);
        $response = $agent->respond($request->input('message'));
        
        return response()->json([
            'response' => $response,
            'notes_count' => $agent->notes()->count(),
            'pending_actions' => $agent->notes()->getPendingActions()->count(),
        ]);
    }
    
    public function getNotes(Request $request, string $customerId)
    {
        $agent = CustomerSupportAgent::for($customerId);
        
        return response()->json([
            'notes' => $agent->notes()->getNotes()->toArray(),
            'by_type' => $agent->notes()->getNotes()->countByType(),
        ]);
    }
    
    public function addNote(Request $request, string $customerId)
    {
        $agent = CustomerSupportAgent::for($customerId);
        
        $note = $agent->notes()->addNote(
            $request->input('content'),
            auth()->id()
        );
        
        // Save context (including notes)
        $agent->saveContext();
        
        return response()->json(['note' => $note->toArray()]);
    }
}
```
