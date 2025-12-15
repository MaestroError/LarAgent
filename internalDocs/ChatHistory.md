# Chat History Configuration and Usage

Chat History in LarAgent is a storage mechanism that manages the conversation history between users and AI agents. It's implemented as a specialized storage type (`ChatHistoryStorage`) built on top of the new storage abstraction system.

## Overview

Chat History is automatically created for each agent and stores all messages exchanged during a conversation. It supports multiple storage backends (drivers), automatic persistence, and integrates with the Context system for unified management.

## Configuration

### Global Configuration (config/laragent.php)

```php
return [
    /**
     * Default chat history storage drivers to use in Agents
     */
    'default_history_storage' => [
        \LarAgent\Context\Drivers\CacheStorage::class, // Primary
        \LarAgent\Context\Drivers\FileStorage::class,  // Secondary (fallback)
    ],
];
```

### Per-Agent Configuration

You can configure chat history storage at the agent level by setting the `$history` property:

```php
use LarAgent\Agent;

class MyAgent extends Agent
{
    // Option 1: Use a built-in alias
    protected $history = 'cache';  // or 'file', 'session', 'in_memory', 'database', 'json'

    // Option 2: Specify driver class(es)
    protected $history = \LarAgent\Context\Drivers\CacheStorage::class;

    // Option 3: Multiple drivers (with fallback)
    protected $history = [
        \LarAgent\Context\Drivers\CacheStorage::class,
        \LarAgent\Context\Drivers\FileStorage::class,
    ];

    // Instructions for the agent
    public function instructions()
    {
        return 'You are a helpful assistant.';
    }
}
```

### Built-in Storage Aliases

| Alias | Driver Class |
|-------|-------------|
| `'in_memory'` | `InMemoryStorage::class` |
| `'session'` | `SessionStorage::class` |
| `'cache'` | `CacheStorage::class` |
| `'file'` | `FileStorage::class` |
| `'json'` | `FileStorage::class` |
| `'database'` | `EloquentStorage::class` |
| `'database-simple'` | `SimpleEloquentStorage::class` |

## Usage

### Accessing Chat History

```php
// Get the chat history instance
$chatHistory = $agent->chatHistory();

// Get all messages
$messages = $agent->chatHistory()->getMessages();

// Get the last message
$lastMessage = $agent->chatHistory()->getLastMessage();

// Get message count
$count = $agent->chatHistory()->count();
```

### Adding Messages Manually

```php
use LarAgent\Message;

// Add a user message
$agent->addMessage(Message::user('Hello!'));

// Add an assistant message
$agent->addMessage(Message::assistant('Hi there!'));

// Add a system message
$agent->addMessage(Message::system('You are a helpful assistant.'));
```

### Clearing History

```php
// Clear all messages in the chat history
$agent->clear();
```

### Converting to Array

```php
// Get messages as array (without metadata)
$array = $agent->chatHistory()->toArray();

// Get messages as array (with metadata)
$arrayWithMeta = $agent->chatHistory()->toArrayWithMeta();
```

## Metadata Storage

You can enable metadata storage to persist additional information with each message (like timestamps, model used, etc.):

### Per-Agent Configuration

```php
class MyAgent extends Agent
{
    protected $storeMeta = true;

    // ...
}
```

### Per-Provider Configuration

```php
// config/laragent.php
'providers' => [
    'default' => [
        // ...
        'store_meta' => true,
    ],
],
```

## Force Read/Write

By default, chat history uses lazy loading and dirty tracking. You can force explicit operations:

### Agent Properties

```php
class MyAgent extends Agent
{
    // Force read history from storage on agent initialization
    protected $forceReadHistory = false;
    
    // Force save history after each agent response
    protected $forceSaveHistory = false;

    // ...
}
```

### Manual Operations

```php
// Force read from storage (bypasses lazy loading)
$agent->chatHistory()->readFromMemory();

// Force write to storage (bypasses dirty check)
$agent->chatHistory()->writeToMemory();

// Standard save (only saves if dirty)
$agent->chatHistory()->save();
```

## Events

Chat History dispatches events at key points:

| Event | Description |
|-------|-------------|
| `MessageAdding` | Before a message is added |
| `MessageAdded` | After a message is added |
| `ChatHistorySaving` | Before saving to storage |
| `ChatHistorySaved` | After saving to storage |
| `ChatHistoryLoaded` | After loading from storage |
| `ChatHistoryTruncated` | After truncation is applied |

### Listening to Events

```php
use LarAgent\Events\ChatHistory\MessageAdded;
use Illuminate\Support\Facades\Event;

Event::listen(MessageAdded::class, function ($event) {
    $chatHistory = $event->chatHistory;
    $message = $event->message;
    
    // Your logic here
});
```

## Multi-User Support

Chat History automatically isolates data per user/session using the agent's identity:

```php
// Create agent for specific user
$agent = MyAgent::forUserId('user-123');

// Or for authenticated user
$agent = MyAgent::forUser($request->user());

// Or with a custom chat key
$agent = MyAgent::for('my-custom-session');
```

## Integration with Context

Chat History is managed through the Context system. You can access it via:

```php
// Via agent
$chatHistory = $agent->chatHistory();

// Via context
$chatHistory = $agent->context()->getStorage(\LarAgent\Context\Storages\ChatHistoryStorage::class);

// Get all chat keys for this agent class
$chatKeys = $agent->getChatKeys();

// Get chat identities
$chatIdentities = $agent->getChatIdentities();
```

## Storage Keys

Each chat history storage is identified by a unique key based on the session identity:

```
{scope}_{agentName}_{userId|chatKey}
```

Example: `chatHistory_MyAgent_user-123`

## Best Practices

1. **Use appropriate storage for your use case:**
   - `in_memory` - For single-request conversations or testing
   - `cache` - For temporary conversations (default)
   - `database` - For persistent, queryable conversations

2. **Configure multiple drivers for redundancy:**
   ```php
   protected $history = [
       CacheStorage::class,  // Primary (fast)
       FileStorage::class,   // Fallback (persistent)
   ];
   ```

3. **Enable metadata storage when you need conversation analytics:**
   ```php
   protected $storeMeta = true;
   ```

4. **Use events for logging, analytics, or side effects:**
   ```php
   Event::listen(MessageAdded::class, function ($event) {
       Log::info('Message added', [
           'agent' => $event->chatHistory->getIdentifier(),
           'role' => $event->message->getRole(),
       ]);
   });
   ```
