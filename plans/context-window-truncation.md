# Context Window & Truncation Handling Plan

## Overview

Implement context window management and automatic truncation strategies to prevent exceeding model token limits. The system will monitor total tokens from the last assistant message's usage data and apply truncation when necessary.

## Key Design Decisions

### 1. Responsibility Split
- **Context Class**: Handles truncation execution (receives messages, applies strategy, returns truncated messages)
- **Agent Class**: Provides truncation strategy via overridable method (for configuration flexibility)
- **Strategy Classes**: Encapsulate different truncation algorithms with their own configurations

### 2. Truncation Control
- `$enableTruncation` property on Agent (similar to `$trackUsage`)
- Three-level priority: Agent property > Provider config > Global config
- Truncation only triggers when total tokens exceed context window size

### 3. Token Source
- Use `totalTokens` from the last assistant message's usage metadata
- Requires usage data to be available on messages (via driver's message formatter)

---

## Implementation Steps

### Step 1: Create Truncation Strategy Contract

Create `src/Context/Contracts/TruncationStrategy.php`:

```php
interface TruncationStrategy
{
    /**
     * Apply truncation to messages array.
     * 
     * @param MessageArray $messages Current chat history
     * @param int $contextWindowSize Maximum allowed tokens
     * @param int $currentTokens Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray;
}
```

### Step 2: Create Base Abstract Strategy

Create `src/Context/Abstract/TruncationStrategy.php`:

```php
abstract class AbstractTruncationStrategy implements TruncationStrategy
{
    protected array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaultConfig(), $config);
    }
    
    abstract protected function defaultConfig(): array;
    
    /**
     * Check if message should be preserved (system/developer messages typically shouldn't be removed)
     */
    protected function shouldPreserve(MessageInterface $message): bool
    {
        return in_array($message->getRole(), ['system', 'developer']);
    }
}
```

### Step 3: Implement Simple Truncation Strategy

Create `src/Context/Truncation/SimpleTruncationStrategy.php`:

```php
class SimpleTruncationStrategy extends AbstractTruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 10, // Number of recent messages to keep
            'preserve_system' => true, // Keep system/developer messages
        ];
    }
    
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        // Remove early messages, keep last N messages
        // Always preserve system/developer messages at the beginning
    }
}
```

### Step 4: Implement Token-Based Truncation Strategy

Create `src/Context/Truncation/TokenBasedTruncationStrategy.php`:

```php
class TokenBasedTruncationStrategy extends AbstractTruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'target_percentage' => 0.75, // Reduce to 75% of context window
            'preserve_system' => true,
        ];
    }
    
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        // Remove early messages while tracking estimated prompt tokens
        // Stop when estimated tokens reach target percentage of context window
    }
}
```

### Step 5: Implement Summarization Strategy

Create `src/Context/Truncation/SummarizationStrategy.php`:

```php
class SummarizationStrategy extends AbstractTruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 5, // Number of recent messages to keep
            'summary_agent' => null, // Agent class name for summarization (required)
            'summary_title' => 'Summary of previous conversation',
            'preserve_system' => true,
        ];
    }
    
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        // 1. Keep system messages and last N messages
        // 2. Summarize middle messages using provided agent
        // 3. Inject summary as developer message
    }
}
```

### Step 6: Implement Symbolization Strategy

Create `src/Context/Truncation/SymbolizationStrategy.php`:

```php
class SymbolizationStrategy extends AbstractTruncationStrategy
{
    protected function defaultConfig(): array
    {
        return [
            'keep_messages' => 5,
            'summary_agent' => null, // Agent for individual message summarization
            'symbol_title' => 'Conversation symbols',
            'preserve_system' => true,
        ];
    }
    
    public function truncate(MessageArray $messages, int $contextWindowSize, int $currentTokens): MessageArray
    {
        // 1. Keep system messages and last N messages
        // 2. Create brief symbol/summary for each middle message
        // 3. Combine all symbols into single developer message
    }
}
```

### Step 7: Add Truncation Support to Context Class

Update `src/Context/Context.php`:

### Step 8: Add Agent Properties and Methods

Update `src/Agent.php`:

```php
class Agent
{
    /**
     * Enable context window truncation.
     * When enabled, truncation is applied before sending messages to LLM.
     * Priority: Agent property > Provider config > Global config
     */
    protected ?bool $enableTruncation = null;
    
    /**
     * Context window size for this agent.
     * If not set, uses provider's default_context_window config.
     */
    protected ?int $contextWindowSize = null;
    
    // ... in constructor after setupContext():
    // $this->setupTruncation();
    
    /**
     * Check if truncation is enabled.
     * Priority: Agent property > Provider config > Global config
     */
    public function shouldTruncate(): bool
    {
        if ($this->enableTruncation !== null) {
            return $this->enableTruncation;
        }
        
        $providerConfig = config("laragent.providers.{$this->provider}.enable_truncation");
        if ($providerConfig !== null) {
            return (bool) $providerConfig;
        }
        
        return config('laragent.enable_truncation', false);
    }
    
    /**
     * Enable or disable truncation.
     */
    public function enableTruncation(bool $enabled = true): static
    {
        $this->enableTruncation = $enabled;
        if ($enabled) {
            $this->setupTruncation();
        }
        return $this;
    }
    
    /**
     * Get the truncation strategy for this agent.
     * Override this method to provide custom strategy configuration.
     */
    protected function truncationStrategy(): ?TruncationStrategy
    {
        // Default: Simple truncation keeping last 10 messages
        return new SimpleTruncationStrategy([
            'keep_messages' => 10,
            'preserve_system' => true,
        ]);
    }
    
    /**
     * Setup truncation on context.
     */
    protected function setupTruncation(): void
    {
        if (!$this->shouldTruncate()) {
            return;
        }
        
        $strategy = $this->truncationStrategy();
        $windowSize = $this->getContextWindowSize();
        
        $this->context()->setTruncationStrategy($strategy);
        $this->context()->setContextWindowSize($windowSize);
    }
    
    /**
     * Get context window size.
     * Priority: Agent property > Provider config > Default
     */
    public function getContextWindowSize(): int
    {
        if ($this->contextWindowSize !== null) {
            return $this->contextWindowSize;
        }
        
        return config(
            "laragent.providers.{$this->provider}.default_context_window",
            128000
        );
    }
}
```

### Step 9: Add replaceMessages Method to ChatHistoryStorage

Update `src/Context/Storages/ChatHistoryStorage.php`:

```php
/**
 * Replace all messages with a new MessageArray.
 * Used by truncation strategies.
 */
public function replaceMessages(MessageArray $messages): void
{
    $this->items = $messages;
    $this->dirty = true;
    
    // Dispatch event
    $this->dispatchEvent(new ChatHistoryTruncated($this, $messages));
}
```

### Step 10: Create ChatHistoryTruncated Event

Create `src/Events/ChatHistory/ChatHistoryTruncated.php`:


### Step 11: Integrate Truncation into Agent Flow

Update Agent's `setupBeforeRespond()` or `prepareAgent()` to apply truncation:

```php
protected function applyTruncationIfNeeded(): void
{
    if (!$this->shouldTruncate()) {
        return;
    }
    
    // Get current tokens from last assistant message
    $lastMessage = $this->chatHistory()->getLastMessage();
    $currentTokens = 0;
    
    if ($lastMessage instanceof Message) {
        $usage = $lastMessage->getMetadata()['usage'] ?? null;
        if ($usage) {
            $currentTokens = $usage['total_tokens'] ?? 0;
        }
    }
    
    // Apply truncation via context
    $this->context()->applyTruncation($this->chatHistory(), $currentTokens);
}
```

### Step 12: Add Configuration Options

Update `config/laragent.php`:

Support global as well as per provider configuration of truncation toggle, strategy and strategy config.

### Step 13: Create Artisan Command for Custom Strategy

Create `src/Commands/MakeTruncationStrategyCommand.php`:

```php
class MakeTruncationStrategyCommand extends Command
{
    protected $signature = 'make:truncation-strategy {name}';
    protected $description = 'Create a new truncation strategy class';
}
```

---

## Usage Examples

### Basic Usage (Agent Property)

```php
class MyAgent extends Agent
{
    protected ?bool $enableTruncation = true;
    protected ?int $contextWindowSize = 16000;
}
```

### Custom Strategy Override

```php
class MyAgent extends Agent
{
    protected ?bool $enableTruncation = true;
    
    protected function truncationStrategy(): ?TruncationStrategy
    {
        return new TokenBasedTruncationStrategy([
            'target_percentage' => 0.80,
            'preserve_system' => true,
        ]);
    }
}
```

### Summarization Strategy

```php
class MyAgent extends Agent
{
    protected ?bool $enableTruncation = true;
    
    protected function truncationStrategy(): ?TruncationStrategy
    {
        return new SummarizationStrategy([
            'keep_messages' => 3,
            'summary_agent' => SummaryAgent::class,
            'summary_title' => 'Previous conversation summary',
        ]);
    }
}
```

### Runtime Configuration

```php
$agent = MyAgent::for('user-123')
    ->enableTruncation(true)
    ->respond('Hello!');
```

---

## Testing Considerations

1. **Unit Tests** (tests/Unit/Context/Truncation/)
   - Test each strategy in isolation with mock messages
   - Test token counting logic
   - Test message preservation logic

2. **Integration Tests** (tests/LarAgent/Context/)
   - Test truncation integration with Agent
   - Test configuration priority (agent > provider > global)
   - Test event dispatching

3. **Manual Tests** (testsManual/)
   - Test with real API calls
   - Test summarization strategy with actual agent
   - Test with various context window sizes

---

## Events

| Event | When Dispatched |
|-------|-----------------|
| `ChatHistoryTruncated` | After truncation is applied to chat history |
| `BeforeTruncation` | Before truncation strategy is executed (optional) |
| `AfterTruncation` | After truncation is complete (optional) |

---

## Notes

1. **Token Estimation**: For strategies that need to estimate tokens, consider using a promptTokens from message usage or allow configuration of a token counter callable.

2. **Message Integrity**: Tool call messages and their results should be kept together to maintain conversation coherence. Strategies should handle this.

3. **Streaming**: Truncation should be applied before the streaming starts, using the last known token count.

---

IMPORTANT: Examples provided above are only for demonstration purposes, real implemention is up to you.
