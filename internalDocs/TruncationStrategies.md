# Truncation Strategies

Truncation strategies manage chat history when conversations exceed the configured context window size. They help maintain conversation continuity while keeping token usage within limits.

## Overview

When conversation history grows too large, truncation strategies determine how to reduce it while preserving important context. LarAgent provides built-in strategies and allows you to create custom ones.

## Configuration

### Global Configuration

```php
// config/laragent.php
return [
    /**
     * Enable context window truncation globally.
     * Priority: Agent property > Provider config > Global config
     */
    'enable_truncation' => false,

    /**
     * Provider for built-in truncation agents (summarizer, symbolizer)
     */
    'truncation_provider' => 'default',

    /**
     * Default truncation strategy class
     */
    'default_truncation_strategy' => \LarAgent\Context\Truncation\SimpleTruncationStrategy::class,

    /**
     * Default configuration for truncation strategies
     */
    'default_truncation_config' => [
        'keep_messages' => 10,
        'preserve_system' => true,
    ],

    /**
     * Context window buffer percentage (0.0 to 1.0)
     * Reserves this percentage of context window for safety
     * Default: 0.2 (20% reserved)
     */
    'context_window_buffer' => 0.2,
];
```

### Per-Agent Configuration

```php
use LarAgent\Agent;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;

class MyAgent extends Agent
{
    // Enable truncation for this agent
    protected $enableTruncation = true;

    // Set context window size (tokens)
    protected $contextWindowSize = 50000;

    // Override the truncation strategy
    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SimpleTruncationStrategy([
            'keep_messages' => 5,
            'preserve_system' => true,
        ]);
    }
}
```

### Runtime Configuration

```php
$agent = MyAgent::make()
    ->enableTruncation(true);
```

## Built-in Strategies

### SimpleTruncationStrategy

Keeps the last N messages, discarding older ones:

```php
use LarAgent\Context\Truncation\SimpleTruncationStrategy;

class MyAgent extends Agent
{
    protected $enableTruncation = true;

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SimpleTruncationStrategy([
            'keep_messages' => 10,      // Keep last 10 messages
            'preserve_system' => true,  // Always keep system/developer messages
        ]);
    }
}
```

**Behavior:**
1. Separates system/developer messages from regular messages
2. Keeps all system messages at the beginning
3. Keeps only the last N regular messages
4. Discards older messages completely

**Best for:**
- Simple use cases where old context isn't important
- Short conversations
- Cost-sensitive applications

### SummarizationStrategy

Summarizes removed messages using an AI agent:

```php
use LarAgent\Context\Truncation\SummarizationStrategy;

class MyAgent extends Agent
{
    protected $enableTruncation = true;

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SummarizationStrategy([
            'keep_messages' => 5,
            'summary_agent' => \LarAgent\BuiltIn\Agents\ChatSummarizerAgent::class,
            'summary_title' => 'Summary of previous conversation',
            'preserve_system' => true,
        ]);
    }
}
```

**Behavior:**
1. Separates system messages from regular messages
2. Takes all but the last N messages
3. Sends them to a summarizer agent
4. Inserts the summary as a developer message
5. Keeps the last N messages after the summary

**Best for:**
- Long conversations where context matters
- Complex multi-turn interactions
- When preserving conversation context is important

**Note:** This strategy makes an additional API call for summarization.

### SymbolizationStrategy

Creates brief symbolic representations of removed messages:

```php
use LarAgent\Context\Truncation\SymbolizationStrategy;

class MyAgent extends Agent
{
    protected $enableTruncation = true;

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SymbolizationStrategy([
            'keep_messages' => 5,
            'symbolizer_agent' => \LarAgent\BuiltIn\Agents\ChatSymbolizerAgent::class,
            'symbol_title' => 'Previous conversation summary',
            'preserve_system' => true,
        ]);
    }
}
```

**Behavior:**
1. Similar to SummarizationStrategy
2. Creates brief symbols/keywords instead of full summaries
3. More token-efficient than full summarization

**Best for:**
- When you need some context but want to minimize tokens
- Balance between SimpleTruncation and Summarization

## How Truncation Works

### Truncation is Applied Before Sending

Truncation happens in `prepareAgent()`, before messages are sent to the LLM:

```php
// Agent.php
protected function prepareAgent(MessageInterface $message): void
{
    // Apply truncation before preparing agent
    $this->applyTruncationIfNeeded();
    
    // ... rest of preparation
}
```

### Token Counting

Truncation uses the `totalTokens` from the last message with usage data:

```php
// Total tokens represents the cumulative conversation token count
// This is provided by the LLM in its usage response
$currentTokens = $this->getLastKnownTotalTokens();
```

**Important:** Truncation only triggers when:
1. Truncation is enabled
2. Messages have usage data (from previous API calls)
3. Current tokens exceed the effective context window

### Effective Context Window

The actual truncation threshold accounts for the buffer:

```php
$effectiveWindowSize = $contextWindowSize * (1.0 - $contextWindowBuffer);
// Example: 50000 * 0.8 = 40000 (with 20% buffer)
```

## Creating Custom Strategies

### Step 1: Create the Strategy Class

```php
<?php

namespace App\TruncationStrategies;

use LarAgent\Context\Abstract\TruncationStrategy;
use LarAgent\Messages\DataModels\MessageArray;

class PriorityTruncationStrategy extends TruncationStrategy
{
    /**
     * Default configuration
     */
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 10,
            'preserve_system' => true,
            'priority_roles' => ['user'],  // Prioritize keeping user messages
        ];
    }

    /**
     * Apply truncation
     */
    public function truncate(
        MessageArray $messages,
        int $contextWindowSize,
        int $currentTokens
    ): MessageArray {
        $keepMessages = $this->getConfig('keep_messages', 10);
        $priorityRoles = $this->getConfig('priority_roles', ['user']);

        if ($messages->count() <= $keepMessages) {
            return $messages;
        }

        $newMessages = new MessageArray();
        $allMessages = $messages->all();

        // Separate by type
        $systemMessages = [];
        $priorityMessages = [];
        $otherMessages = [];

        foreach ($allMessages as $message) {
            if ($this->shouldPreserve($message)) {
                $systemMessages[] = $message;
            } elseif (in_array($message->getRole(), $priorityRoles)) {
                $priorityMessages[] = $message;
            } else {
                $otherMessages[] = $message;
            }
        }

        // Add system messages first
        foreach ($systemMessages as $message) {
            $newMessages->add($message);
        }

        // Calculate how many messages to keep
        $remaining = $keepMessages;

        // Prioritize recent messages of priority roles
        $recentPriority = array_slice($priorityMessages, -$remaining);
        foreach ($recentPriority as $message) {
            $newMessages->add($message);
        }

        // Fill remaining with other messages (most recent)
        $remaining = $keepMessages - count($recentPriority);
        if ($remaining > 0) {
            $recentOther = array_slice($otherMessages, -$remaining);
            foreach ($recentOther as $message) {
                $newMessages->add($message);
            }
        }

        return $newMessages;
    }
}
```

### Step 2: Use via Artisan Command (Optional)

LarAgent provides an artisan command to scaffold strategies:

```bash
php artisan make:truncation-strategy MyCustomStrategy
```

This creates a file at `app/TruncationStrategies/MyCustomStrategy.php`.

### Step 3: Use in Agent

```php
class MyAgent extends Agent
{
    protected $enableTruncation = true;

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new \App\TruncationStrategies\PriorityTruncationStrategy([
            'keep_messages' => 15,
            'preserve_system' => true,
            'priority_roles' => ['user', 'assistant'],
        ]);
    }
}
```

## Events

Truncation dispatches an event when applied:

```php
use LarAgent\Events\ChatHistory\ChatHistoryTruncated;
use Illuminate\Support\Facades\Event;

Event::listen(ChatHistoryTruncated::class, function ($event) {
    $chatHistory = $event->chatHistory;
    $newMessages = $event->messages;
    
    Log::info('Chat history truncated', [
        'identifier' => $chatHistory->getIdentifier(),
        'new_count' => $newMessages->count(),
    ]);
});
```

## Context Window Guidelines

### Setting Context Window Size

The agent's context window should be **lower** than the model's actual limit:

```php
// Model has 128K context
// Set agent to 50K (40% of model limit)
protected $contextWindowSize = 50000;
```

**Recommendations:**
- **Conservative:** 20-30% of model's context window
- **Balanced:** 30-50% of model's context window  
- **Aggressive:** Up to 80% (may cause issues with large responses)

### Buffer Percentage

The buffer reserves space for new messages and responses:

```php
// config/laragent.php
'context_window_buffer' => 0.2,  // 20% buffer
```

**Recommendations:**
- **0.1 (10%):** When agent context is already conservative
- **0.2 (20%):** Balanced approach (default)
- **0.3 (30%):** Extra safety for unpredictable message sizes

## Best Practices

### 1. Enable Truncation for Long Conversations

```php
class ChatAgent extends Agent
{
    protected $enableTruncation = true;
    protected $contextWindowSize = 30000;  // Conservative
}
```

### 2. Choose Strategy Based on Use Case

| Use Case | Strategy |
|----------|----------|
| Simple Q&A | SimpleTruncationStrategy |
| Support conversations | SummarizationStrategy |
| Cost-sensitive apps | SimpleTruncationStrategy |
| Complex workflows | SummarizationStrategy |
| Token-efficient context | SymbolizationStrategy |

### 3. Monitor Token Usage

```php
class MyAgent extends Agent
{
    protected $enableTruncation = true;
    protected $trackUsage = true;  // Enable to provide token data for truncation

    public function afterResponse($message)
    {
        $this->trackUsageFromMessage($message);
        
        // Log current conversation size
        Log::debug('Conversation tokens', [
            'total' => $message->getUsage()?->totalTokens,
        ]);
    }
}
```

### 4. Test Truncation Behavior

```php
// In tests
test('truncation preserves system messages', function () {
    $agent = MyAgent::make();
    
    // Add system message
    $agent->addMessage(Message::system('Important context'));
    
    // Add many messages to trigger truncation
    for ($i = 0; $i < 100; $i++) {
        $agent->addMessage(Message::user("Message $i"));
        // Simulate usage data
        $assistantMsg = Message::assistant("Response $i");
        $assistantMsg->setUsage(new Usage(1000 * $i, 100, 1100 * $i));
        $agent->addMessage($assistantMsg);
    }
    
    // Trigger truncation
    $agent->respond('Final message');
    
    // Verify system message is preserved
    $messages = $agent->chatHistory()->getMessages();
    $firstMessage = $messages->all()[0];
    expect($firstMessage->getRole())->toBe('system');
});
```

### 5. Handle Truncation Failures Gracefully

Summarization strategies make API calls that can fail:

```php
// SummarizationStrategy handles this internally
// On failure, it returns a basic summary:
// "Previous conversation contained N messages."
```

You can customize error handling by extending the strategy.
