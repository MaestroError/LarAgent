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
│  - ...                                      │
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
│  - ...                                       │
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

    // Each storage defines its data model array
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

LarAgent provides three built-in storage implementations that work together to manage agent state. Each storage uses the same driver system but stores different types of data.

### ChatHistoryStorage

Stores conversation messages between the user and the AI. This is the primary storage most developers will interact with.

**Key features:**
- Stores messages as typed `MessageArray` (polymorphic: user, assistant, system, tool messages)
- Supports metadata storage for additional context
- Fires events: `MessageAdding`, `MessageAdded`, `ChatHistorySaving`, `ChatHistorySaved`, `ChatHistoryLoaded`

**Agent usage:**
```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Message;

class SupportAgent extends Agent
{
    protected $instructions = 'You are a helpful support agent.';
    protected $history = 'cache';  // Configure storage driver
    protected $storeMeta = true;   // Enable metadata storage
    
    public function demonstrateChatHistory(): void
    {
        // Access the chat history
        $chatHistory = $this->chatHistory();
        
        // Get all messages
        $messages = $chatHistory->getMessages();
        
        // Get the last message
        $lastMessage = $chatHistory->getLastMessage();
        
        // Get message count
        $count = $chatHistory->count();
        
        // Add messages manually
        $this->addMessage(Message::user('Hello!'));
        $this->addMessage(Message::assistant('Hi there! How can I help?'));
        
        // Convert to array (for API responses)
        $messagesArray = $chatHistory->toArray();
        
        // With metadata included
        $messagesWithMeta = $chatHistory->toArrayWithMeta();
        
        // Clear all messages
        $this->clear();
        
        // Manual persistence control
        $chatHistory->save();           // Save if dirty
        $chatHistory->writeToMemory();  // Force write
        $chatHistory->readFromMemory(); // Force read
    }
}
```

**Controller usage:**
```php
class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $agent = SupportAgent::for($request->user()->id);
        $response = $agent->respond($request->input('message'));
        
        return response()->json([
            'response' => $response,
            'message_count' => $agent->chatHistory()->count(),
            'history' => $agent->chatHistory()->toArray(),
        ]);
    }
    
    public function clearHistory(Request $request)
    {
        $agent = SupportAgent::for($request->user()->id);
        $agent->clear();
        
        return response()->json(['status' => 'cleared']);
    }
}
```

### UsageStorage

Tracks token usage and costs across agent interactions. Useful for monitoring, billing, and analytics.

**Key features:**
- Stores `UsageRecord` objects with token counts, costs, and metadata
- Supports filtering by agent, user, model, provider, and date ranges
- Provides aggregation and grouping methods
- Automatically captures model and provider information

**Agent usage:**
```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;

class SupportAgent extends Agent
{
    protected $instructions = 'You are a helpful support agent.';
    
    public function demonstrateUsageTracking(): void
    {
        // Access usage storage
        $usageStorage = $this->context()->getUsageStorage();
        
        // Get all usage records
        $records = $usageStorage->getUsageRecords();
        
        // Get the last usage record
        $lastUsage = $usageStorage->getLastUsage();
        
        // Filter by various criteria
        $filtered = $usageStorage->getFilteredUsage([
            'agent_name' => 'SupportAgent',
            'user_id' => 'user-123',
            'model_name' => 'gpt-4',
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
        ]);
        
        // Aggregate statistics
        $stats = $usageStorage->aggregate();
        // Returns: ['prompt_tokens' => X, 'completion_tokens' => Y, 'total_tokens' => Z, 'total_cost' => $]
        
        // Group by field
        $byModel = $usageStorage->groupBy('model_name');
        $byUser = $usageStorage->groupBy('user_id');
    }
}
```

**Analytics controller example:**
```php
class UsageAnalyticsController extends Controller
{
    public function dashboard(Request $request)
    {
        $agent = SupportAgent::for($request->user()->id);
        $usageStorage = $agent->context()->getUsageStorage();
        
        // Get usage for current month
        $monthlyUsage = $usageStorage->getFilteredUsage([
            'date_from' => now()->startOfMonth()->toDateString(),
        ]);
        
        return response()->json([
            'total_tokens' => $monthlyUsage->aggregate()['total_tokens'],
            'total_cost' => $monthlyUsage->aggregate()['total_cost'],
            'by_model' => $usageStorage->groupBy('model_name'),
            'record_count' => $monthlyUsage->count(),
        ]);
    }
}
```

### IdentityStorage

Tracks all storage identities (keys) registered within a context. Enables discovery and bulk operations across all agent sessions.

**Key features:**
- Automatically tracks identities when storages are created
- Enables listing all chats/sessions for an agent
- Supports bulk cleanup operations
- Excludes temporary sessions (prefixed with `_temp`)

**Agent usage via Context Facade:**
```php
use LarAgent\Facades\Context;

// List all chat sessions for an agent
$identities = Context::of(SupportAgent::class)->getIdentities();

foreach ($identities as $identity) {
    echo "Chat: {$identity->getChatName()}, User: {$identity->getUserId()}\n";
}

// Filter by user
$userIdentities = Context::of(SupportAgent::class)
    ->forUser('user-123') // user id
    ->getIdentities();

// Clear all chats for an agent
Context::of(SupportAgent::class)->clearAllChats();

// Clear all chats for a specific user
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clearAllChats();

// Clear a specific chat
Context::of(SupportAgent::class)
    ->forChat('session-456')
    ->clearChat();
```

**Admin controller example:**
```php
class AgentAdminController extends Controller
{
    public function listSessions(string $agentClass)
    {
        $identities = Context::of($agentClass)->getIdentities();
        
        return response()->json([
            'sessions' => $identities->toArray(),
            'total' => $identities->count(),
        ]);
    }
    
    public function clearUserSessions(Request $request, string $userId)
    {
        Context::of(SupportAgent::class)
            ->forUser($userId)
            ->clearAllChats();
        
        return response()->json(['status' => 'cleared']);
    }
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

Storage drivers handle the actual persistence of data. LarAgent provides several built-in drivers that can be configured at the agent level.

### Configuring Storage in Agent Class

There are multiple ways to configure which storage driver(s) an agent uses:

#### 1. Using String Aliases (Simplest)

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;

class SupportAgent extends Agent
{
    protected $instructions = 'You are a helpful support agent.';
    
    // Use a built-in storage alias
    protected $history = 'cache';
}
```

**Available aliases:**
| Alias | Driver Class | Description |
|-------|-------------|-------------|
| `'in_memory'` | `InMemoryStorage` | No persistence, lost on request end |
| `'session'` | `SessionStorage` | PHP session storage |
| `'cache'` | `CacheStorage` | Laravel cache (Redis, Memcached, etc.) |
| `'file'` or `'json'` | `FileStorage` | JSON files on disk |
| `'database'` | `EloquentStorage` | Separate row per message |
| `'database-simple'` | `SimpleEloquentStorage` | JSON column storage |

#### 2. Using Driver Classes

```php
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;

class SupportAgent extends Agent
{
    // Single driver
    protected $history = CacheStorage::class;
    
    // Or with fallback chain (primary first)
    protected $history = [
        CacheStorage::class,   // Primary: read first, write first
        FileStorage::class,    // Fallback: used if primary fails
    ];
}
```

#### 3. Override Method for Dynamic Configuration

```php
class SupportAgent extends Agent
{
    protected function historyStorageDrivers(): string|array
    {
        // Dynamic driver selection based on environment
        if (app()->environment('testing')) {
            return 'in_memory';
        }
        
        return [
            new CacheStorage('redis'),
            new FileStorage('local', 'chat_backups'),
        ];
    }
}
```

#### 4. Full Control via `createChatHistory()`

```php
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Drivers\EloquentStorage;

class SupportAgent extends Agent
{
    public function createChatHistory()
    {
        $drivers = [
            new EloquentStorage(\App\Models\CustomMessage::class),
        ];
        
        return new ChatHistoryStorage(
            $this->context()->getIdentity(),
            $drivers,
            storeMeta: true  // Enable metadata storage
        );
    }
}
```

### CacheStorage

Uses Laravel's cache system. Ideal for fast access with configurable backends (Redis, Memcached, file, database).

```php
use LarAgent\Context\Drivers\CacheStorage;

// Default cache store (uses config('cache.default'))
$driver = new CacheStorage();

// Specific cache store
$driver = new CacheStorage('redis');
$driver = new CacheStorage('memcached');
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'cache';
    
    // Or with custom store
    protected function historyStorageDrivers(): array
    {
        return [new CacheStorage('redis')];
    }
}
```

**Best for:** High-traffic applications, when you need fast read/write access and have Redis/Memcached available.

### FileStorage

Stores data as JSON files. Works with any Laravel filesystem disk (local, S3, etc.).

```php
use LarAgent\Context\Drivers\FileStorage;

// Default settings (local disk, 'laragent' folder)
$driver = new FileStorage();

// Custom disk and folder
$driver = new FileStorage(
    disk: 's3',
    folder: 'ai_conversations'
);

// Local disk with custom folder
$driver = new FileStorage(
    disk: 'local',
    folder: 'chat_history'
);
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'file';
    
    // Or with S3 backup
    protected function historyStorageDrivers(): array
    {
        return [
            new CacheStorage('redis'),           // Fast primary
            new FileStorage('s3', 'backups'),    // Durable fallback
        ];
    }
}
```

**Best for:** Simple deployments, when you need file-based persistence, or as a fallback to cache storage.

### EloquentStorage

Full Eloquent integration with each item stored as a separate database row. Best for queryable, structured data.

```php
use LarAgent\Context\Drivers\EloquentStorage;

// Default LaragentMessage model
$driver = new EloquentStorage();

// Custom model
$driver = new EloquentStorage(\App\Models\ChatMessage::class);

// Configure column names (if using custom model)
$driver = new EloquentStorage(\App\Models\ChatMessage::class);
$driver->setKeyColumn('session_key');
$driver->setPositionColumn('sort_order');
```

**Setup required:**
```bash
# Publish and run migration
php artisan la:publish eloquent-storage
php artisan migrate
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'database';
    
    // Or with custom model
    protected function historyStorageDrivers(): array
    {
        return [
            new EloquentStorage(\App\Models\ConversationMessage::class),
        ];
    }
}
```

**Best for:** When you need to query messages directly, analytics, audit trails, or integration with existing database models.

### SimpleEloquentStorage

Stores the entire data array as JSON in a single database column. Simpler but less queryable.

```php
use LarAgent\Context\Drivers\SimpleEloquentStorage;

// Default LaragentStorage model
$driver = new SimpleEloquentStorage();

// Custom model
$driver = new SimpleEloquentStorage(\App\Models\StorageRecord::class);
```

**Setup required:**
```bash
# Publish and run migration
php artisan la:publish simple-eloquent-storage
php artisan migrate
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'database-simple';
}
```

**Best for:** When you need database persistence but don't need to query individual messages.

### SessionStorage

Uses PHP session for storage. Data persists across requests within the same session.

```php
use LarAgent\Context\Drivers\SessionStorage;

$driver = new SessionStorage();
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'session';
}
```

**Best for:** Web applications where each user has their own session, simple chat widgets.

**Note:** Not suitable for API-only applications or when sessions aren't available.

### InMemoryStorage

No persistence - data exists only in PHP memory for the current request.

```php
use LarAgent\Context\Drivers\InMemoryStorage;

$driver = new InMemoryStorage();
```

**Agent usage:**
```php
class SupportAgent extends Agent
{
    protected $history = 'in_memory';
}
```

**Best for:** Testing, one-off interactions, stateless API endpoints, or when persistence isn't needed.

**Note:** Using this with other drivers in a chain doesn't make sense - data is lost when the request ends.

### Working with Chat History in Agent

Once storage is configured, you can interact with chat history through the agent:

```php
// Get the chat history instance
$chatHistory = $agent->chatHistory();

// Get all messages
$messages = $chatHistory->getMessages();

// Get the last message
$lastMessage = $chatHistory->getLastMessage();

// Get message count
$count = $chatHistory->count();

// Add messages manually
use LarAgent\Message;
$agent->addMessage(Message::user('Hello!'));
$agent->addMessage(Message::assistant('Hi there!'));

// Clear chat history
$agent->clear();

// Manual save (usually automatic)
$agent->chatHistory()->save();
```

### Storage Driver Selection Guide

| Use Case | Recommended Driver(s) |
|----------|----------------------|
| Development/Testing | `in_memory` |
| Simple web app | `session` or `cache` |
| Production with Redis | `cache` (redis) |
| Need message queries | `database` (EloquentStorage) |
| Simple DB persistence | `database-simple` |
| High availability | `cache` + `file` (fallback chain) |
| Serverless/Lambda | `database` or `database-simple` |
| Long-running processes | `cache` or `database` with `forceReadHistory = true` |


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

namespace App\Enums;

enum NoteType: string
{
    case Text = 'text';
    case Action = 'action';
    case Reminder = 'reminder';
}

enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;
use App\Enums\NoteType;
use App\Enums\Priority;

class CustomerNote extends DataModel
{
    #[Desc('Type of note')]
    public NoteType $type = NoteType::Text;
    
    #[Desc('The note content')]
    public string $content;
    
    #[Desc('ISO 8601 timestamp')]
    public string $createdAt;
    
    #[Desc('User who created the note')]
    public ?string $createdBy = null;
    
    #[Desc('Priority level')]
    public Priority $priority = Priority::Medium;
    
    #[Desc('Whether the note is resolved')]
    public bool $resolved = false;
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;
use App\Enums\NoteType;
use App\Enums\Priority;

class CustomerNoteArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        // Single type - no discriminator needed
        return [CustomerNote::class];
    }
    
    // Filter by type (accepts enum or string)
    public function ofType(NoteType|string $type): static
    {
        $typeValue = $type instanceof NoteType ? $type : NoteType::from($type);
        return $this->filter(fn($note) => $note->type === $typeValue);
    }
    
    // Filter by priority
    public function highPriority(): static
    {
        return $this->filter(fn($note) => $note->priority === Priority::High);
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
        return $this->getNotes()->ofType(NoteType::Action)->unresolved();
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
use LarAgent\Attributes\Tool;
use App\Context\Storages\CustomerNotesStorage;
use App\DataModels\CustomerNote;
use App\Enums\NoteType;
use App\Enums\Priority;

class CustomerSupportAgent extends Agent
{
    protected $instructions = 'You are a customer support agent.';
    
    protected $history = 'database';
    
    protected function onInitialize(): void
    {
        // Register notes storage with context
        $this->context()->make(CustomerNotesStorage::class);
    }
    
    /**
     * Get customer notes storage.
     */
    public function notes(): CustomerNotesStorage
    {
        return $this->context()->getStorage(CustomerNotesStorage::class);
    }
    
    /**
     * Tool: Add a note about the customer.
     */
    #[Tool('Add a note about this customer interaction')]
    public function addCustomerNote(
        string $content,
        NoteType $type = NoteType::Text,
        Priority $priority = Priority::Medium
    ): string {
        // Create DataModel and use the inherited add() method
        $note = CustomerNote::fromArray([
            'type' => $type->value,
            'content' => $content,
            'createdAt' => now()->toIso8601String(),
            'createdBy' => auth()->id(),
            'priority' => $priority->value,
        ]);
        
        $this->notes()->add($note);
        
        return "Note added: {$note->content}";
    }
    
    /**
     * Tool: Get pending actions for this customer.
     */
    #[Tool('Get pending action items for this customer')]
    public function getPendingActions(): string
    {
        $actions = $this->notes()->getPendingActions();
        
        if ($actions->count() === 0) {
            return "No pending actions.";
        }
        
        $list = [];
        foreach ($actions as $action) {
            $list[] = "- [{$action->priority->value}] {$action->content}";
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
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Facades\Context;

class CustomerSupportController extends Controller
{
    /**
     * Chat with the agent - notes are managed automatically via tools.
     */
    public function chat(Request $request)
    {
        $agent = CustomerSupportAgent::forUser($request->user());
        $response = $agent->respond($request->input('message'));
        
        return response()->json([
            'response' => $response,
            'notes_count' => $agent->notes()->count(),
            'pending_actions' => $agent->notes()->getPendingActions()->count(),
        ]);
    }
    
    /**
     * Update a note manually from the user side.
     * Use when users need to edit notes outside of agent interaction.
     */
    public function updateNote(Request $request, int $noteIndex)
    {
        $agent = CustomerSupportAgent::forUser($request->user());
        
        $notes = $agent->notes()->getNotes();
        
        if (!isset($notes[$noteIndex])) {
            return response()->json(['error' => 'Note not found'], 404);
        }
        
        // Update note properties
        $notes[$noteIndex]->content = $request->input('content');
        $notes[$noteIndex]->resolved = $request->input('resolved');
        
        if ($request->has('priority')) {
            $notes[$noteIndex]->priority = Priority::from($request->input('priority'));
        }
        
        // Use set() to mark storage as dirty, then save
        $agent->notes()->set($notes);
        $agent->saveContext();
        
        return response()->json(['note' => $note->toArray()]);
    }
    
    /**
     * Remove note using named approach with manual storage construction.
     * 
     * When agent instance is not needed (no AI interaction), you can build
     * the storage directly with the correct identity. This bypasses agent 
     * initialization (tool registration, MCP connection, etc.) and is much 
     * faster for direct storage operations.
     */
    public function removeNote(Request $request, int $noteIndex)
    {
        $drivers = [CacheStorage::class];
        
        // Get identity from named context - filters tracked identities
        $identity = Context::named('CustomerSupportAgent')
            ->withDrivers($drivers)
            ->forUser($request->user())
            ->forStorage(CustomerNotesStorage::class)
            ->first();
        
        if (!$identity) {
            return response()->json(['error' => 'No notes found for user'], 404);
        }
        
        // Create storage directly - no agent initialization needed
        $notes = new CustomerNotesStorage($identity, $drivers);
        
        try {
            $notes->removeItemOrFail($noteIndex);
            $notes->save();
        } catch (\OutOfBoundsException $e) {
            return response()->json(['error' => 'Note not found'], 404);
        }
        
        return response()->json(['status' => 'removed']);
    }
    
    /**
     * Clear all notes using Context facade.
     * 
     * Uses Context facade with agent class - initializes a temp agent 
     * internally to access the context with proper configuration.
     * Simpler API but has agent initialization overhead.
     */
    public function clearNotes(Request $request)
    {
        Context::of(CustomerSupportAgent::class)
            ->forUser($request->user())
            ->forStorage(CustomerNotesStorage::class)
            ->clear();
        
        return response()->json(['status' => 'cleared']);
    }
}
```

_Note: The named approach is faster for simple CRUD operations since it skips agent initialization. Use `Context::of()` when you need access to agent configuration or when simplicity is preferred over performance._

