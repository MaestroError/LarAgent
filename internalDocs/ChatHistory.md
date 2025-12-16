# Chat History Configuration and Usage

The Chat History system in LarAgent stores and manages conversation messages between users and AI agents. It provides automatic persistence, lazy loading, and flexible storage driver configuration.

## Overview

Chat history in LarAgent is managed through the `ChatHistoryStorage` class, which extends the base `Storage` abstraction. It automatically:

- Persists messages across requests
- Maintains message order
- Supports multiple storage drivers (cache, file, database, session)
- Provides lazy loading for performance
- Tracks dirty state to avoid unnecessary writes

## Configuration Levels

### 1. Global Configuration (config/laragent.php)

Set the default history storage drivers for all agents:

```php
// config/laragent.php

return [
    /**
     * Default chat history storage drivers to use in Agents
     * Uses driver chain - first driver is primary, others are fallback
     */
    'default_history_storage' => [
        \LarAgent\Context\Drivers\CacheStorage::class, // Primary
        \LarAgent\Context\Drivers\FileStorage::class,  // Fallback
    ],

    // ...
];
```

### 2. Per-Provider Configuration (config/laragent.php)

Configure history storage for specific providers:

```php
// config/laragent.php

return [
    'providers' => [
        'default' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            // Provider-specific history storage
            'history' => [
                \LarAgent\Context\Drivers\EloquentStorage::class,
            ],
        ],
        
        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            // Different storage for Gemini provider
            'history' => [
                \LarAgent\Context\Drivers\CacheStorage::class,
            ],
        ],
    ],
];
```

### 3. Per-Agent Property Configuration

Set storage drivers directly in your agent class:

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;

class SupportAgent extends Agent
{
    protected $instructions = 'You are a helpful support agent.';
    
    /**
     * Configure chat history storage drivers.
     * Can be an array of driver classes or a string alias.
     */
    protected $history = [
        CacheStorage::class,
        FileStorage::class,
    ];
}
```

Using string aliases for built-in drivers:

```php
class SupportAgent extends Agent
{
    // Use string alias for built-in storage
    protected $history = 'cache';  // or 'file', 'session', 'in_memory', 'database', 'database-simple'
}
```

Available aliases:
- `'in_memory'` - `InMemoryStorage` (no persistence, lost on request end)
- `'session'` - `SessionStorage` (PHP session storage)
- `'cache'` - `CacheStorage` (Laravel cache)
- `'file'` / `'json'` - `FileStorage` (JSON files)
- `'database'` - `EloquentStorage` (full Eloquent with separate rows per message)
- `'database-simple'` - `SimpleEloquentStorage` (JSON column storage)

### 4. Per-Agent Method Override

Override the `createChatHistory()` method for complete control:

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Drivers\EloquentStorage;

class CustomAgent extends Agent
{
    protected $instructions = 'You are a custom agent.';
    
    /**
     * Create a custom chat history instance.
     */
    public function createChatHistory()
    {
        // Custom driver configuration
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

## Available Storage Drivers

### InMemoryStorage
Messages are stored only in PHP memory. Lost when the request ends.

```php
protected $history = 'in_memory';
// or
protected $history = [\LarAgent\Context\Drivers\InMemoryStorage::class];
```

### SessionStorage
Uses PHP sessions. Good for web applications with session support.

```php
protected $history = 'session';
// or
protected $history = [\LarAgent\Context\Drivers\SessionStorage::class];
```

### CacheStorage
Uses Laravel's cache system. Supports any cache driver (Redis, Memcached, file, etc.).

```php
protected $history = 'cache';
// or
protected $history = [\LarAgent\Context\Drivers\CacheStorage::class];
```

### FileStorage
Stores messages as JSON files.

```php
protected $history = 'file';
// or
protected $history = [\LarAgent\Context\Drivers\FileStorage::class];
```

### EloquentStorage
Full Eloquent storage with each message as a separate database row. Requires migration.

```bash
# Publish the migration
php artisan la:publish eloquent-storage

# Run migration
php artisan migrate
```

```php
protected $history = 'database';
// or
protected $history = [\LarAgent\Context\Drivers\EloquentStorage::class];
```

### SimpleEloquentStorage
Stores the entire message array as JSON in a single database column. Simpler but less queryable.

```bash
# Publish the migration
php artisan la:publish simple-eloquent-storage

# Run migration
php artisan migrate
```

```php
protected $history = 'database-simple';
// or
protected $history = [\LarAgent\Context\Drivers\SimpleEloquentStorage::class];
```

## Working with Chat History

### Accessing Chat History

```php
// Get the chat history instance
$chatHistory = $agent->chatHistory();

// Get all messages
$messages = $chatHistory->getMessages();

// Get the last message
$lastMessage = $chatHistory->getLastMessage();

// Get message count
$count = $chatHistory->count();

// Convert to array
$messagesArray = $chatHistory->toArray();
```

### Adding Messages Manually

```php
use LarAgent\Message;

// Add a user message
$agent->addMessage(Message::user('Hello, I need help.'));

// Add an assistant message
$agent->addMessage(Message::assistant('Of course! How can I help you?'));

// Add a system message
$agent->addMessage(Message::system('Additional context...'));
```

### Clearing Chat History

```php
// Clear all messages (keeps the session)
$agent->clear();

// Using Context Facade for bulk operations
use LarAgent\Facades\Context;

// Clear all chats for an agent
Context::of(SupportAgent::class)->clearAllChats();

// Clear chats for a specific user
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clearAllChats();
```

### Force Read/Save Operations

```php
// Force read from storage (bypass cache)
$agent->chatHistory()->read();

// Force save to storage (bypass dirty check)
$agent->chatHistory()->save();
```

## Metadata Storage

By default, only message content is stored. Enable metadata storage to persist additional information:

### Enable via Property

```php
class SupportAgent extends Agent
{
    /**
     * Store message metadata
     */
    protected $storeMeta = true;
}
```

### Enable via Provider Config

```php
'providers' => [
    'default' => [
        'store_meta' => true,
        // ...
    ],
],
```

### What Metadata Contains

```php
// Metadata automatically added to messages
[
    'agent' => 'SupportAgent',  // Agent name
    'model' => 'gpt-4',         // Model used
    // Custom metadata added via $message->addMeta([...])
]
```

## Force Read/Save Flags

Control when chat history is synchronized with storage:

```php
class SupportAgent extends Agent
{
    /**
     * Force read history from storage on agent initialization
     */
    protected $forceReadHistory = false;
    
    /**
     * Force save history to storage after each agent response
     */
    protected $forceSaveHistory = false;
}
```

## Chat History Events

Listen to chat history events for custom behavior:

```php
// In a service provider or listener

use LarAgent\Events\ChatHistory\MessageAdding;
use LarAgent\Events\ChatHistory\MessageAdded;
use LarAgent\Events\ChatHistory\ChatHistorySaving;
use LarAgent\Events\ChatHistory\ChatHistorySaved;
use LarAgent\Events\ChatHistory\ChatHistoryLoaded;
use LarAgent\Events\ChatHistory\ChatHistoryTruncated;

// Before a message is added
Event::listen(MessageAdding::class, function ($event) {
    $chatHistory = $event->chatHistory;
    $message = $event->message;
    // Modify or validate message
});

// After a message is added
Event::listen(MessageAdded::class, function ($event) {
    $chatHistory = $event->chatHistory;
    $message = $event->message;
    // Log, notify, etc.
});

// Before saving to storage
Event::listen(ChatHistorySaving::class, function ($event) {
    $messages = $event->messages;
    // Last chance to modify before persistence
});

// After saving to storage
Event::listen(ChatHistorySaved::class, function ($event) {
    // Trigger post-save actions
});

// After loading from storage
Event::listen(ChatHistoryLoaded::class, function ($event) {
    $messages = $event->messages;
    // Process loaded messages
});

// After truncation is applied
Event::listen(ChatHistoryTruncated::class, function ($event) {
    $messages = $event->messages;
    // Log truncation, analyze removed messages
});
```

## Real-World Scenario: Customer Support System

### Requirements
- Store chat history persistently in database for long-term access
- Support multiple concurrent conversations per user
- Enable metadata for analytics
- Different storage for different environments

### Implementation

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Drivers\EloquentStorage;
use LarAgent\Context\Drivers\CacheStorage;

class CustomerSupportAgent extends Agent
{
    protected $instructions = <<<PROMPT
You are a helpful customer support agent for ACME Corp.
Be polite, professional, and thorough in your responses.
Always verify customer identity before discussing account details.
PROMPT;

    protected $model = 'gpt-4';
    
    // Store metadata for analytics
    protected $storeMeta = true;
    
    // Use database storage for persistence
    protected $history = 'database';
    
    // Force save after each response for reliability
    protected $forceSaveHistory = true;
}
```

### Usage in Controller

```php
<?php

namespace App\Http\Controllers;

use App\AiAgents\CustomerSupportAgent;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function chat(Request $request)
    {
        $user = $request->user();
        $ticketId = $request->input('ticket_id');
        
        // Create agent for specific user and ticket
        $agent = CustomerSupportAgent::forUser($user)
            ->message($request->input('message'));
        
        $response = $agent->respond();
        
        return response()->json([
            'response' => $response,
            'history_count' => $agent->chatHistory()->count(),
        ]);
    }
    
    public function getHistory(Request $request)
    {
        $user = $request->user();
        
        $agent = CustomerSupportAgent::forUser($user);
        
        return response()->json([
            'messages' => $agent->chatHistory()->toArray(),
        ]);
    }
    
    public function clearHistory(Request $request)
    {
        $user = $request->user();
        
        CustomerSupportAgent::forUser($user)->clear();
        
        return response()->json(['status' => 'cleared']);
    }
}
```

### Admin Panel: Manage All Conversations

```php
<?php

namespace App\Http\Controllers\Admin;

use LarAgent\Facades\Context;
use App\AiAgents\CustomerSupportAgent;

class SupportAdminController extends Controller
{
    public function listAllConversations()
    {
        $identities = Context::of(CustomerSupportAgent::class)
            ->getIdentities();
        
        return response()->json([
            'total' => $identities->count(),
            'conversations' => $identities->map(fn($i) => [
                'user_id' => $i->getUserId(),
                'chat_name' => $i->getChatName(),
                'key' => $i->getKey(),
            ])->all(),
        ]);
    }
    
    public function getUserConversations(string $userId)
    {
        return Context::of(CustomerSupportAgent::class)
            ->forUser($userId)
            ->map(function ($identity, $agent) {
                return [
                    'chat_name' => $identity->getChatName(),
                    'message_count' => $agent->chatHistory()->count(),
                    'last_message' => $agent->lastMessage()?->getContentAsString(),
                ];
            });
    }
    
    public function clearUserHistory(string $userId)
    {
        Context::of(CustomerSupportAgent::class)
            ->forUser($userId)
            ->clearAllChats();
        
        return response()->json(['status' => 'cleared']);
    }
}
```
