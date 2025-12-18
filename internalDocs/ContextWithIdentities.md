# Context with Identities Configuration and Usage

The Context and Identity system in LarAgent provides a unified way to manage storage isolation, session tracking, and data scoping across agents and users.

## Overview

The Context system consists of several key components:

1. **SessionIdentity**: Uniquely identifies a storage session using agent name, user ID, chat name, group, and scope.
2. **Context**: A central orchestration layer that manages multiple storage instances for an agent.
3. **IdentityStorage**: Tracks all storage identities registered within a context.
4. **Storage**: Abstract base for all storage implementations (chat history, usage, custom).

## Session Identity

A `SessionIdentity` uniquely identifies a storage key using these components:

| Component | Description | Example |
|-----------|-------------|---------|
| `agentName` | Name of the agent class | `'SupportAgent'` |
| `chatName` | Session/chat identifier | `'support-ticket-123'` |
| `userId` | User identifier (when using `forUser()`) | `'user-456'` |
| `group` | Optional grouping (e.g., organization, tenant) | `'premium'` |
| `scope` | Storage type scope | `'chatHistory'`, `'usage'` |

### Key Generation

The identity key is generated as:
```
{scope}_{group|agentName}_{userId|chatName|'default'}
```

Examples:
- `chatHistory_SupportAgent_user-123` - User-based chat history
- `chatHistory_SupportAgent_session-abc` - Session-based chat history
- `usage_premium_user-456` - User usage in premium group

## Creating Agents with Identities

### Basic Agent Creation (Session-based)

Use session-based creation when you need to manage conversations by a custom session key rather than user identity. This is ideal for anonymous users, guest sessions, or when you want explicit control over session naming.

```php
// Create agent with a specific session key
$agent = SupportAgent::for('session-123');

// Create agent with random session key
$agent = SupportAgent::make();

// Access identity
$identity = $agent->context()->getIdentity();
echo $identity->getChatName();  // 'session-123'
echo $identity->getAgentName(); // 'SupportAgent'
```

### User-based Agent Creation

Use user-based creation to automatically tie conversations to authenticated users. The identity key will include the user ID, enabling easy querying and management of all conversations for a specific user.

```php
// Create agent for authenticated user
$agent = SupportAgent::forUser($request->user());

// Create agent for specific user ID
$agent = SupportAgent::forUserId('user-123');

// Access identity
$identity = $agent->context()->getIdentity();
echo $identity->getUserId();    // 'user-123'
echo $identity->getAgentName(); // 'SupportAgent'
```

### Reconstruct Agent from Identity

Use `fromIdentity()` to recreate an agent instance from a previously tracked identity. This is useful for admin panels, background jobs, or any scenario where you need to operate on existing conversations without knowing the original creation parameters.

```php
use LarAgent\Facades\Context;

// Get an identity from storage
$identity = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->first();

// Reconstruct the agent from identity
$agent = SupportAgent::fromIdentity($identity);

// The agent is now configured with the same session context
$response = $agent->respond('Continue our conversation...');
```

## Grouping

Groups enable **shared context** between multiple agents. When a group is set, the storage key uses the group name **instead of** the agent name. This means agents in the same group with the same user/chat identifiers will share the same context data.

**Key format without group:** `{scope}_{agentName}_{userId|chatName|'default'}`  
**Key format with group:** `{scope}_{group}_{userId|chatName|'default'}`

This is useful for multi-tenant applications where agents should share context within a tenant, or when you want multiple agent types to work with the same conversation history.

### Setting Group via Class Property

```php
class TenantSupportAgent extends Agent
{
    // Static group assignment
    protected $group = 'tenant-123';
}
```

### Setting Group Dynamically

For runtime group resolution (e.g., based on current tenant):

```php
class TenantSupportAgent extends Agent
{
    public function group(): ?string
    {
        return tenant()->id ?? null;
    }
}
```

> **Note:** The group setting applies to **all** storages by default. To use different groups for specific storages (e.g., group usage but not chat history), override `createChatHistory()` or `createUsageStorage()` with a custom identity:

```php
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;

class MyAgent extends Agent
{
    protected $group = 'shared-group'; // Default group for all storages
    
    // Override to use agent-specific identity for chat history (no grouping)
    public function createChatHistory()
    {
        $identity = new SessionIdentity(
            agentName: $this->getAgentName(),
            chatName: $this->getChatKey(),
            userId: $this->getUserId(),
            group: null, // No group - uses agentName instead
        );
        
        return new ChatHistoryStorage(
            $identity,
            $this->historyStorageDrivers(),
            $this->storeMeta ?? false
        );
    }
}
```

### Query by Group

Use the Context facade to query identities or perform bulk operations on all sessions within a group:

```php
use LarAgent\Facades\Context;

// Get all sessions for a group
$identities = Context::of(SupportAgent::class)
    ->forGroup('premium')
    ->getIdentities();

// Clear all chats for a group
Context::of(SupportAgent::class)
    ->forGroup('enterprise')
    ->clearAllChats();
```

## Context Operations

### Accessing Context

You can access the context object directly from agent:

```php
// Get the context instance
$context = $agent->context();

// Get the base identity
$identity = $context->getIdentity();

// Get the context identity (used for IdentityStorage)
$contextIdentity = $context->getContextIdentity();
```

_note: `contextIdentity` is general identity of the agent, it is stored in IdentityStorage and holds all other storage identities associated to the agent._

### Registering Custom Storages

```php
use LarAgent\Context\Abstract\Storage;

// Register a storage instance
$context->register($customStorage);

// Create and register from class
$storage = $context->make(CustomStorage::class);

// Get registered storage
$customStorage = $context->getStorage(CustomStorage::class);

// Or by prefix
$chatHistory = $context->getStorage('CustomPrefix');
```

Check custom storages for more info

### Bulk Operations

```php
// Save all dirty storages
$context->save();

// Read all storages from drivers
$context->read();

// Clear all storages (marks as dirty, sets to empty)
$context->clear();

// Remove all storages completely
$context->remove();
```

## Identity Tracking

The Context automatically tracks all registered storage identities for discovery and management.

### Getting Tracked Keys

```php
// Get all tracked storage keys
$keys = $agent->getStorageKeys();
// ['chatHistory_SupportAgent_user-123', 'usage_SupportAgent_user-123', ...]

// Get chat history keys only
$chatKeys = $agent->getChatKeys();

// Get chat history identities
$chatIdentities = $agent->getChatIdentities();
```

### Context Facade Queries

```php
use LarAgent\Facades\Context;

// Count all sessions
$total = Context::of(SupportAgent::class)->count();

// Check if user has sessions
$exists = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->exists();

// Get first matching identity
$identity = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->first();

// Get first as agent instance
$agent = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->firstAgent();

// Get all identities
$all = Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->all();
```

### Filtering Identities

```php
use LarAgent\Facades\Context;

// Filter by user
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->clearAllChats();

// Filter by chat name
Context::of(SupportAgent::class)
    ->forChat('support-ticket-456')
    ->remove();

// Filter by group
Context::of(SupportAgent::class)
    ->forGroup('premium')
    ->count();

// Filter by storage type
use LarAgent\Context\Storages\ChatHistoryStorage;

Context::of(SupportAgent::class)
    ->forStorage(ChatHistoryStorage::class)
    ->count();

// Custom filter
Context::of(SupportAgent::class)
    ->filter(function ($identity) {
        return str_starts_with($identity->getChatName(), 'vip-');
    })
    ->clearAllChats();

// Combine filters
Context::of(SupportAgent::class)
    ->forUser('user-123')
    ->forGroup('premium')
    ->forStorage(ChatHistoryStorage::class)
    ->remove();
```

### Named Context Manager

For operations that don't need a full agent class:

```php
use LarAgent\Facades\Context;
use LarAgent\Context\Drivers\CacheStorage;

// Lightweight - doesn't initialize agent
Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->forUser('user-123')
    ->clearAllChats();

// Get identity storage directly
$identityStorage = Context::named('SupportAgent')
    ->withDrivers([CacheStorage::class])
    ->context()
    ->getIdentityStorage();
```

Check context facade docs for more info.


## Context Events

Listen to context lifecycle events:

```php
use LarAgent\Events\Context\ContextCreated;
use LarAgent\Events\Context\ContextSaving;
use LarAgent\Events\Context\ContextSaved;
use LarAgent\Events\Context\ContextReading;
use LarAgent\Events\Context\ContextRead;
use LarAgent\Events\Context\ContextClearing;
use LarAgent\Events\Context\ContextCleared;
use LarAgent\Events\Context\StorageRegistered;

// Context created
Event::listen(ContextCreated::class, function ($event) {
    $context = $event->context;
    Log::info('Context created', ['identity' => $context->getIdentity()->getKey()]);
});

// Storage registered
Event::listen(StorageRegistered::class, function ($event) {
    $context = $event->context;
    $prefix = $event->prefix;
    $storage = $event->storage;
    Log::info('Storage registered', ['prefix' => $prefix]);
});

// Before saving
Event::listen(ContextSaving::class, function ($event) {
    // Last chance to modify before persistence
});

// After saving
Event::listen(ContextSaved::class, function ($event) {
    // Trigger post-save actions
});
```

## Identity Storage Events

```php
use LarAgent\Events\IdentityStorage\IdentityAdding;
use LarAgent\Events\IdentityStorage\IdentityAdded;
use LarAgent\Events\IdentityStorage\IdentityStorageSaving;
use LarAgent\Events\IdentityStorage\IdentityStorageSaved;
use LarAgent\Events\IdentityStorage\IdentityStorageLoaded;

// Before identity is added
Event::listen(IdentityAdding::class, function ($event) {
    $identity = $event->identity;
    // Can modify or validate
});

// After identity is added (only when actually new)
Event::listen(IdentityAdded::class, function ($event) {
    $identity = $event->identity;
    Log::info('New session tracked', ['key' => $identity->getKey()]);
});
```

## Temporary Sessions

Sessions prefixed with `_temp` are not tracked in IdentityStorage:

```php
// This session won't be tracked
$agent = SupportAgent::for('_temp_preview');

// Useful for:
// - Preview/demo sessions
// - Test sessions
// - One-time interactions
```

## Real-World Scenario: Multi-Tenant SaaS Application

### Requirements
- Each tenant has isolated AI conversations
- Users within tenants have separate sessions
- Admin can manage all tenant data
- Support for multiple agent types per tenant

### Implementation

#### 1. Tenant-Aware Base Agent

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;

abstract class TenantAwareAgent extends Agent
{
    /**
     * Get the current tenant ID for grouping.
     * This automatically applies to all storages via the identity system.
     */
    public function group(): ?string
    {
        return tenant()?->id;
    }
}
```

#### 2. Tenant-Specific Agents

```php
<?php

namespace App\AiAgents;

class TenantSupportAgent extends TenantAwareAgent
{
    protected $instructions = <<<PROMPT
You are a support agent for {tenant_name}.
Follow the company guidelines and policies.
PROMPT;

    protected $history = 'database';
    protected $trackUsage = true;
}

class TenantCodeAssistant extends TenantAwareAgent
{
    protected $instructions = 'You are a code assistant.';
    protected $history = 'database';
    protected $trackUsage = true;
}
```

#### 3. Multi-Tenant Service

```php
<?php

namespace App\Services;

use LarAgent\Facades\Context;
use App\AiAgents\TenantSupportAgent;
use App\AiAgents\TenantCodeAssistant;

class TenantAgentService
{
    protected array $agentClasses = [
        TenantSupportAgent::class,
        TenantCodeAssistant::class,
    ];
    
    /**
     * Get all conversations for a tenant.
     */
    public function getTenantConversations(string $tenantId): array
    {
        $conversations = [];
        
        foreach ($this->agentClasses as $agentClass) {
            $identities = Context::of($agentClass)
                ->forGroup($tenantId)
                ->getIdentities();
            
            foreach ($identities as $identity) {
                $conversations[] = [
                    'agent' => class_basename($agentClass),
                    'user_id' => $identity->getUserId(),
                    'chat_name' => $identity->getChatName(),
                    'key' => $identity->getKey(),
                ];
            }
        }
        
        return $conversations;
    }
    
    /**
     * Get all conversations for a user within a tenant.
     */
    public function getUserConversations(string $tenantId, string $userId): array
    {
        $conversations = [];
        
        foreach ($this->agentClasses as $agentClass) {
            $identities = Context::of($agentClass)
                ->forGroup($tenantId)
                ->forUser($userId)
                ->getIdentities();
            
            foreach ($identities as $identity) {
                $agent = $agentClass::fromIdentity($identity);
                $conversations[] = [
                    'agent' => class_basename($agentClass),
                    'key' => $identity->getKey(),
                    'message_count' => $agent->chatHistory()->count(),
                    'last_message' => $agent->lastMessage()?->getContentAsString(),
                ];
            }
        }
        
        return $conversations;
    }
    
    /**
     * Clear all data for a tenant (e.g., when tenant is deleted).
     */
    public function clearTenantData(string $tenantId): int
    {
        $clearedCount = 0;
        
        foreach ($this->agentClasses as $agentClass) {
            // Get count before removing
            $count = Context::of($agentClass)
                ->forGroup($tenantId)
                ->count();
            
            // Remove all chats (returns static for chaining)
            Context::of($agentClass)
                ->forGroup($tenantId)
                ->removeAllChats();
            
            $clearedCount += $count;
        }
        
        return $clearedCount;
    }
    
    /**
     * Clear all data for a user within a tenant.
     */
    public function clearUserData(string $tenantId, string $userId): void
    {
        foreach ($this->agentClasses as $agentClass) {
            Context::of($agentClass)
                ->forGroup($tenantId)
                ->forUser($userId)
                ->removeAllChats();
        }
    }
    
    /**
     * Get usage statistics for a tenant.
     */
    public function getTenantUsage(string $tenantId): array
    {
        $usage = [];
        
        foreach ($this->agentClasses as $agentClass) {
            $identities = Context::of($agentClass)
                ->forGroup($tenantId)
                ->getIdentities();
            
            $totalTokens = 0;
            $recordCount = 0;
            
            foreach ($identities as $identity) {
                $agent = $agentClass::fromIdentity($identity);
                $stats = $agent->getUsageAggregate();
                
                if ($stats) {
                    $totalTokens += $stats['total_tokens'] ?? 0;
                    $recordCount += $stats['record_count'] ?? 0;
                }
            }
            
            $usage[class_basename($agentClass)] = [
                'total_tokens' => $totalTokens,
                'record_count' => $recordCount,
            ];
        }
        
        return $usage;
    }
}
```

#### 4. Controller for Multi-Tenant API

```php
<?php

namespace App\Http\Controllers;

use App\Services\TenantAgentService;
use App\AiAgents\TenantSupportAgent;
use Illuminate\Http\Request;

class TenantAgentController extends Controller
{
    public function __construct(
        protected TenantAgentService $service
    ) {}
    
    /**
     * Chat with support agent.
     */
    public function chat(Request $request)
    {
        $user = $request->user();
        $message = $request->input('message');
        
        $agent = TenantSupportAgent::forUser($user);
        $response = $agent->respond($message);
        
        return response()->json([
            'response' => $response,
            'session_key' => $agent->getChatSessionId(),
        ]);
    }
    
    /**
     * Get user's conversation history.
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $tenantId = tenant()->id;
        
        $conversations = $this->service->getUserConversations($tenantId, (string) $user->id);
        
        return response()->json(['conversations' => $conversations]);
    }
    
    /**
     * Admin: Get all tenant conversations.
     */
    public function adminListConversations(Request $request)
    {
        $tenantId = $request->input('tenant_id', tenant()->id);
        
        return response()->json([
            'conversations' => $this->service->getTenantConversations($tenantId),
        ]);
    }
    
    /**
     * Admin: Clear tenant data (GDPR compliance).
     */
    public function adminClearTenantData(Request $request)
    {
        $tenantId = $request->input('tenant_id');
        
        $count = $this->service->clearTenantData($tenantId);
        
        return response()->json([
            'status' => 'success',
            'cleared_sessions' => $count,
        ]);
    }
    
    /**
     * Admin: Get tenant usage report.
     */
    public function adminUsageReport(Request $request)
    {
        $tenantId = $request->input('tenant_id', tenant()->id);
        
        return response()->json([
            'usage' => $this->service->getTenantUsage($tenantId),
        ]);
    }
}
```

#### 5. Scheduled Cleanup Job

```php
<?php

namespace App\Jobs;

use LarAgent\Facades\Context;
use App\AiAgents\TenantSupportAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CleanupInactiveSessions implements ShouldQueue
{
    use Queueable;
    
    protected int $inactiveDays = 30;
    
    public function handle()
    {
        // This example assumes you have a way to check session age
        // In practice, you'd query based on last activity timestamp
        
        Context::of(TenantSupportAgent::class)
            ->filter(function ($identity) {
                // Add your inactive session detection logic
                // Could check database timestamps, etc.
                return false; // Placeholder
            })
            ->removeAllChats();
    }
}
```
