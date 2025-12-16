# Truncation Strategies Setup and Usage

Truncation strategies in LarAgent manage conversations that exceed the model's context window. When chat history grows too large, these strategies automatically reduce the conversation size while preserving important context.

## Overview

Truncation strategies:
1. Monitor the token count of the conversation
2. Trigger when tokens exceed a configurable threshold
3. Remove or compress older messages
4. Preserve system messages and recent context
5. Optionally summarize or symbolize removed content

## Built-in Strategies

### SimpleTruncationStrategy

Keeps the last N messages, discarding older ones. Fast and simple.

```php
// Keeps last 10 messages, removes the rest
$strategy = new SimpleTruncationStrategy([
    'keep_messages' => 10,
    'preserve_system' => true,
]);
```

### SummarizationStrategy

Summarizes removed messages using an AI agent, preserving context.

```php
// Summarizes removed messages into a developer message
$strategy = new SummarizationStrategy([
    'keep_messages' => 5,
    'summary_agent' => ChatSummarizerAgent::class,
    'summary_title' => 'Summary of previous conversation',
    'preserve_system' => true,
]);
```

### SymbolizationStrategy

Creates brief "symbols" for each removed message, providing a timeline.

```php
// Creates one-line symbols for each removed message
$strategy = new SymbolizationStrategy([
    'keep_messages' => 5,
    'summary_agent' => ChatSymbolizerAgent::class,
    'symbol_title' => 'Conversation symbols',
    'preserve_system' => true,
    'batch_size' => 10,
]);
```

## Configuration Levels

### 1. Global Configuration (config/laragent.php)

```php
// config/laragent.php

return [
    /**
     * Enable truncation globally for all agents.
     * Priority: Agent property > Provider config > Global config
     */
    'enable_truncation' => false,

    /**
     * Provider to use for built-in truncation agents.
     * Used by SummarizationStrategy and SymbolizationStrategy.
     */
    'truncation_provider' => 'default',

    /**
     * Default truncation strategy class.
     */
    'default_truncation_strategy' => \LarAgent\Context\Truncation\SimpleTruncationStrategy::class,

    /**
     * Default configuration for truncation strategies.
     */
    'default_truncation_config' => [
        'keep_messages' => 10,
        'preserve_system' => true,
    ],

    /**
     * Truncation buffer percentage (0.0 to 1.0).
     * Reserves this percentage of the threshold for safety margin.
     * Default: 0.2 (20% reserved, 80% available for history)
     */
    'truncation_buffer' => 0.2,

    /**
     * Provider configurations with truncation thresholds.
     */
    'providers' => [
        'default' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            
            // Token threshold for truncation (NOT the model's context window)
            // Should be 30-50% of model's actual context window
            'default_truncation_threshold' => 50000,
            
            // Optional: Enable truncation for this provider
            'enable_truncation' => true,
        ],
        
        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            // Gemini has larger context, higher threshold
            'default_truncation_threshold' => 1000000,
        ],
    ],
];
```

### 2. Per-Provider Configuration

```php
// config/laragent.php

'providers' => [
    'default' => [
        'label' => 'openai',
        'enable_truncation' => true,
        'default_truncation_threshold' => 50000,
    ],
    
    'gemini' => [
        'label' => 'gemini',
        'enable_truncation' => true,
        'default_truncation_threshold' => 500000,
    ],
    
    // Disable truncation for a specific provider
    'groq' => [
        'label' => 'groq',
        'enable_truncation' => false,
    ],
],
```

### 3. Per-Agent Property Configuration

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;

class LongConversationAgent extends Agent
{
    protected $instructions = 'You are a helpful assistant.';
    
    /**
     * Enable truncation for this agent.
     * Set to true/false to override config, or null to use config.
     */
    protected $enableTruncation = true;
    
    /**
     * Token threshold for truncation.
     * Set lower than model's context window.
     */
    protected $truncationThreshold = 30000;
}
```

### 4. Per-Agent Method Override (Full Control)

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Truncation\SummarizationStrategy;

class CustomTruncationAgent extends Agent
{
    protected $instructions = 'You are a helpful assistant.';
    
    /**
     * Override to provide a custom truncation strategy.
     */
    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SummarizationStrategy([
            'keep_messages' => 20,
            'summary_agent' => \App\AiAgents\CustomSummarizerAgent::class,
            'summary_title' => 'Previous conversation context',
            'preserve_system' => true,
        ]);
    }
    
    /**
     * Override to check if truncation should be enabled.
     */
    public function shouldTruncate(): bool
    {
        // Custom logic - e.g., only truncate in production
        if (app()->environment('testing')) {
            return false;
        }
        
        return parent::shouldTruncate();
    }
    
    /**
     * Override to set a custom truncation threshold.
     */
    public function getTruncationThreshold(): int
    {
        // Dynamic threshold based on model
        return match ($this->model()) {
            'gpt-4' => 50000,
            'gpt-3.5-turbo' => 10000,
            default => 30000,
        };
    }
}
```

## Runtime Configuration

### Enable/Disable Dynamically

```php
// Enable truncation at runtime
$agent = SupportAgent::for('session-123')
    ->enableTruncation(true);

$response = $agent->respond('Hello!');

// Disable truncation
$agent->enableTruncation(false);
```

### Set Strategy via Context

```php
use LarAgent\Context\Truncation\SymbolizationStrategy;

$agent = SupportAgent::for('session-123');

// Configure truncation via context
$agent->context()
    ->setTruncationStrategy(new SymbolizationStrategy([
        'keep_messages' => 10,
    ]))
    ->setTruncationThreshold(40000)
    ->setTruncationBuffer(0.25);

$response = $agent->respond('Continue our conversation...');
```

## Understanding Truncation Thresholds

### Important: Threshold vs Context Window

The `truncationThreshold` is NOT the model's context window. It should be set significantly lower:

```
┌─────────────────────────────────────────────────────────────────┐
│                     Model Context Window                         │
│                        (e.g., 128K tokens)                       │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │              Agent Truncation Threshold                     │ │
│  │                (e.g., 40K tokens)                           │ │
│  │                                                             │ │
│  │  ┌───────────────────────────────────────────────────────┐ │ │
│  │  │         Effective Threshold (after buffer)            │ │ │
│  │  │              (e.g., 32K tokens)                        │ │ │
│  │  │                                                       │ │ │
│  │  │  ┌─────────────────────────────────────────────────┐ │ │ │
│  │  │  │            Chat History                         │ │ │ │
│  │  │  │         (stored messages)                       │ │ │ │
│  │  │  └─────────────────────────────────────────────────┘ │ │ │
│  │  └───────────────────────────────────────────────────────┘ │ │
│  │       ↑ Buffer (20%) reserved for new content              │ │
│  └─────────────────────────────────────────────────────────────┘ │
│      ↑ Remaining headroom for system prompt, tools, etc.        │
└─────────────────────────────────────────────────────────────────┘
```

**Recommendations:**
- **Maximum**: 80% of model's context window (aggressive)
- **Recommended**: 30-50% (balanced)
- **Conservative**: 20-30% (for agents with large tool outputs)

### Buffer Configuration

The buffer reserves space for new messages:

```php
// Reserve 30% for new content (conservative)
'truncation_buffer' => 0.3,

// Reserve 20% for new content (default)
'truncation_buffer' => 0.2,

// Reserve 10% for new content (aggressive)
'truncation_buffer' => 0.1,
```

**Effective threshold calculation:**
```
effective_threshold = truncation_threshold × (1 - buffer)

Example:
truncation_threshold = 50000
buffer = 0.2 (20%)
effective_threshold = 50000 × 0.8 = 40000 tokens
```

## Creating Custom Truncation Strategies

### Using Artisan Command

```bash
php artisan make:truncation-strategy CustomTruncation
```

This creates `app/TruncationStrategies/CustomTruncationStrategy.php`:

```php
<?php

namespace App\TruncationStrategies;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class CustomTruncationStrategy extends TruncationStrategy
{
    /**
     * Get the default configuration for this strategy.
     */
    protected function defaultConfig(): array
    {
        return [
            'preserve_system' => true,
            // Add your custom configuration here
        ];
    }

    /**
     * Apply truncation to messages array.
     *
     * @param  MessageArray  $messages  Current chat history
     * @param  int  $truncationThreshold  Maximum allowed tokens (effective threshold after buffer)
     * @param  int  $currentTokens  Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $truncationThreshold, int $currentTokens): MessageArray
    {
        // Implement your truncation logic here
        return $messages;
    }
}
```

### Example: Priority-Based Truncation

Keep messages marked as important:

```php
<?php

namespace App\TruncationStrategies;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class PriorityTruncationStrategy extends TruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 10,
            'preserve_system' => true,
            'priority_metadata_key' => 'priority',
            'high_priority_value' => 'high',
        ];
    }

    public function truncate(MessageArray $messages, int $truncationThreshold, int $currentTokens): MessageArray
    {
        $keepMessages = $this->getConfig('keep_messages', 10);
        $priorityKey = $this->getConfig('priority_metadata_key');
        $highPriorityValue = $this->getConfig('high_priority_value');
        
        if ($messages->count() <= $keepMessages) {
            return $messages;
        }
        
        $newMessages = new MessageArray;
        $allMessages = $messages->all();
        
        // Separate messages by type
        $systemMessages = [];
        $highPriorityMessages = [];
        $regularMessages = [];
        
        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
            } elseif ($this->isHighPriority($message, $priorityKey, $highPriorityValue)) {
                $highPriorityMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }
        
        // Add system messages first
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }
        
        // Add all high priority messages
        foreach ($highPriorityMessages as $message) {
            $newMessages->add($message);
        }
        
        // Keep last N regular messages
        $regularToKeep = max(0, $keepMessages - count($highPriorityMessages));
        $recentRegular = array_slice($regularMessages, -$regularToKeep);
        foreach ($recentRegular as $message) {
            $newMessages->add($message);
        }
        
        return $newMessages;
    }
    
    protected function isHighPriority($message, string $key, string $value): bool
    {
        if (!method_exists($message, 'getMetadata')) {
            return false;
        }
        
        $metadata = $message->getMetadata();
        return ($metadata[$key] ?? null) === $value;
    }
}
```

### Example: Time-Based Truncation

Keep messages from the last N hours:

```php
<?php

namespace App\TruncationStrategies;

use Carbon\Carbon;
use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class TimeBasedTruncationStrategy extends TruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'keep_hours' => 24,
            'preserve_system' => true,
            'min_messages' => 5,
        ];
    }

    public function truncate(MessageArray $messages, int $truncationThreshold, int $currentTokens): MessageArray
    {
        $keepHours = $this->getConfig('keep_hours', 24);
        $minMessages = $this->getConfig('min_messages', 5);
        $cutoff = Carbon::now()->subHours($keepHours);
        
        $newMessages = new MessageArray;
        $allMessages = $messages->all();
        
        $systemMessages = [];
        $recentMessages = [];
        
        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
                continue;
            }
            
            // Check timestamp from metadata
            $timestamp = $this->getMessageTimestamp($message);
            if ($timestamp && Carbon::parse($timestamp)->isAfter($cutoff)) {
                $recentMessages[] = $message;
            }
        }
        
        // Ensure minimum messages
        if (count($recentMessages) < $minMessages) {
            // Get additional recent messages
            $regularMessages = array_filter($allMessages, function ($m) {
                return !$this->shouldPreserve($m);
            });
            $recentMessages = array_slice($regularMessages, -$minMessages);
        }
        
        // Build result
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }
        foreach ($recentMessages as $message) {
            $newMessages->add($message);
        }
        
        return $newMessages;
    }
    
    protected function getMessageTimestamp($message): ?string
    {
        if (!method_exists($message, 'getMetadata')) {
            return null;
        }
        
        $metadata = $message->getMetadata();
        return $metadata['timestamp'] ?? $metadata['created_at'] ?? null;
    }
}
```

## Truncation Events

Listen to truncation events:

```php
use LarAgent\Events\ChatHistory\ChatHistoryTruncated;

Event::listen(ChatHistoryTruncated::class, function ($event) {
    $chatHistory = $event->chatHistory;
    $newMessages = $event->messages;
    
    Log::info('Chat history truncated', [
        'new_count' => $newMessages->count(),
        'session' => $chatHistory->getIdentifier(),
    ]);
});
```

## Real-World Scenario: Long-Running Support Conversations

### Requirements
- Support agents have multi-day conversations
- Important information must be preserved
- Use summarization for context
- Different strategies for different ticket priorities

### Implementation

#### 1. Custom Summarizer Agent

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;

class SupportSummarizerAgent extends Agent
{
    protected $provider = 'default'; // Uses truncation_provider from config
    
    protected $instructions = <<<PROMPT
You are a support conversation summarizer. Your task is to create concise summaries of customer support conversations.

When summarizing:
1. Identify the main issue or request
2. Note any important customer details (account info, preferences)
3. Track the resolution status
4. Highlight any follow-up actions needed
5. Keep technical details if relevant

Be concise but comprehensive. Focus on actionable information.
PROMPT;
}
```

#### 2. Priority-Aware Agent

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Truncation\SummarizationStrategy;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use App\TruncationStrategies\PriorityTruncationStrategy;

class SupportAgent extends Agent
{
    protected $instructions = 'You are a helpful customer support agent.';
    
    protected $enableTruncation = true;
    protected $truncationThreshold = 40000;
    
    protected string $ticketPriority = 'normal';
    
    public function setTicketPriority(string $priority): static
    {
        $this->ticketPriority = $priority;
        return $this;
    }
    
    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        // Use different strategies based on ticket priority
        return match ($this->ticketPriority) {
            'critical' => new SummarizationStrategy([
                'keep_messages' => 30,
                'summary_agent' => SupportSummarizerAgent::class,
                'preserve_system' => true,
            ]),
            'high' => new PriorityTruncationStrategy([
                'keep_messages' => 20,
                'preserve_system' => true,
            ]),
            'normal' => new SimpleTruncationStrategy([
                'keep_messages' => 15,
                'preserve_system' => true,
            ]),
            'low' => new SimpleTruncationStrategy([
                'keep_messages' => 10,
                'preserve_system' => true,
            ]),
            default => parent::truncationStrategy(),
        };
    }
}
```

#### 3. Controller with Priority Handling

```php
<?php

namespace App\Http\Controllers;

use App\AiAgents\SupportAgent;
use App\Models\Ticket;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function chat(Request $request, Ticket $ticket)
    {
        $user = $request->user();
        
        $agent = SupportAgent::forUser($user)
            ->setTicketPriority($ticket->priority);
        
        // Mark important messages
        $message = \LarAgent\Message::user($request->input('message'));
        
        if ($request->has('mark_important')) {
            $message->addMeta(['priority' => 'high']);
        }
        
        $agent->addMessage($message);
        $response = $agent->respond();
        
        return response()->json([
            'response' => $response,
            'truncation_active' => $agent->shouldTruncate(),
            'message_count' => $agent->chatHistory()->count(),
        ]);
    }
}
```

#### 4. Scheduled Truncation Job

For proactive truncation during off-hours:

```php
<?php

namespace App\Jobs;

use LarAgent\Facades\Context;
use App\AiAgents\SupportAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class TruncateLongConversations implements ShouldQueue
{
    use Queueable;
    
    protected int $messageThreshold = 100;
    
    public function handle()
    {
        Context::of(SupportAgent::class)
            ->each(function ($identity, $agent) {
                $count = $agent->chatHistory()->count();
                
                if ($count > $this->messageThreshold) {
                    // Force truncation by sending a system message
                    $agent->enableTruncation(true)
                        ->addMessage(\LarAgent\Message::system(
                            'Conversation context has been optimized for continued assistance.'
                        ))
                        ->respond('Continue from where we left off.');
                    
                    Log::info('Truncated long conversation', [
                        'session' => $identity->getKey(),
                        'original_count' => $count,
                        'new_count' => $agent->chatHistory()->count(),
                    ]);
                }
            });
    }
}
```

### Schedule the Job

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    // Run truncation job during off-hours
    $schedule->job(new TruncateLongConversations)
        ->dailyAt('03:00')
        ->timezone('America/New_York');
}
```

## Debugging Truncation

### Check Truncation Status

```php
$agent = SupportAgent::for('session-123');

// Check if truncation is enabled
$enabled = $agent->shouldTruncate();

// Get threshold
$threshold = $agent->getTruncationThreshold();

// Get current token count (from last message with usage)
$lastMessage = $agent->lastMessage();
if ($lastMessage && method_exists($lastMessage, 'getUsage')) {
    $usage = $lastMessage->getUsage();
    $currentTokens = $usage?->totalTokens ?? 0;
}

// Get context truncation settings
$context = $agent->context();
$strategy = $context->getTruncationStrategy();
$contextThreshold = $context->getTruncationThreshold();
$buffer = $context->getTruncationBuffer();
```

### Log Truncation Events

```php
// In EventServiceProvider

protected $listen = [
    \LarAgent\Events\ChatHistory\ChatHistoryTruncated::class => [
        \App\Listeners\LogTruncation::class,
    ],
];
```

```php
<?php

namespace App\Listeners;

use LarAgent\Events\ChatHistory\ChatHistoryTruncated;
use Illuminate\Support\Facades\Log;

class LogTruncation
{
    public function handle(ChatHistoryTruncated $event)
    {
        Log::channel('ai')->info('Chat history truncated', [
            'session' => $event->chatHistory->getIdentifier(),
            'new_message_count' => $event->messages->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
```
