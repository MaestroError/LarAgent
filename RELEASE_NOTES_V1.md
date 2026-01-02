# LarAgent v1.0 Release Notes

We're excited to announce **LarAgent v1.0** - a major release that brings powerful new features for building AI agents in Laravel. This release focuses on enhanced developer experience, improved context management, and production-ready tooling.

---

## 🎉 Highlights

### 🛠️ New Artisan Command: `make:agent:tool`

Creating custom tools for your agents is now easier than ever with the new `make:agent:tool` artisan command.

```bash
php artisan make:agent:tool WeatherTool
```

This command:
- Creates an `AgentTools` directory in your `app/` folder if it doesn't exist
- Generates a ready-to-customize tool class with all the necessary boilerplate
- Returns the full path for easy IDE navigation (Ctrl+Click support)

**Example generated tool:**
```php
// app/AgentTools/WeatherTool.php
namespace App\AgentTools;

use LarAgent\Tool;

class WeatherTool extends Tool
{
    protected string $name = 'weather_tool';
    protected string $description = 'Describe what this tool does';

    public function handle(string $location): string
    {
        // Your tool implementation here
        return "Weather data for {$location}";
    }
}
```

---

### 🔧 Tool Call Debugging in `agent:chat`

The `agent:chat` command now displays tool calls in the console, providing real-time visibility into your agent's decision-making process.

```
You: Search for Laravel documentation
Tool call: web_search
Tool call: extract_content

AgentName:
Here is the information I found...
```

This enhancement makes debugging and testing agents significantly easier by showing which tools are being called during conversations.

---

### ⚡ MCP Tool Caching

MCP (Model Context Protocol) tools now support automatic caching, dramatically improving agent initialization performance. Since MCP tools require network calls to fetch definitions from MCP servers, caching eliminates this latency for subsequent requests.

**Configuration:**
```env
MCP_TOOL_CACHE_ENABLED=true
MCP_TOOL_CACHE_TTL=3600
MCP_TOOL_CACHE_STORE=redis  # Optional: use dedicated store
```

**How it works:**
- First request fetches tools from MCP server and caches them
- Subsequent requests load from cache instantly (no network call)
- Cache is shared across all users/agents for efficiency

**Clear cache when needed:**
```bash
php artisan agent:tool-clear
```

The cache clearing command is production-safe for Redis (uses `SCAN` instead of `KEYS`).

---

### 📊 Usage Tracking

Track token consumption across your agents with the new usage tracking system.

**Enable in your agent:**
```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
}
```

**Access usage data:**
```php
$agent = MyAgent::for('user-123');
$usage = $agent->usageStorage();

// Get all usage records
$records = $usage->getRecords();

// Get total tokens
$totalTokens = $usage->getTotalTokens();
$promptTokens = $usage->getTotalPromptTokens();
$completionTokens = $usage->getTotalCompletionTokens();

// Get records by date range
$recentRecords = $usage->getRecordsSince(now()->subDays(7));
```

---

### ✂️ Automatic Conversation Truncation

LarAgent now provides intelligent strategies for managing conversation length when approaching context window limits.

**Available Strategies:**

1. **Sliding Window** (Default) - Removes oldest messages to stay within limits
2. **Summarization** - Summarizes older messages using AI before removing them
3. **Symbolization** - Replaces messages with symbolic representations
4. **Time-Based** - Removes messages older than a specified duration

**Configuration:**
```php
class MyAgent extends Agent
{
    protected $enableTruncation = true;
    protected $truncationThreshold = 50000; // tokens
    
    // Use custom strategy
    protected function truncationStrategy()
    {
        return new SummarizationStrategy();
    }
}
```

---

### 🗄️ Database Storage Drivers

Built-in Eloquent and SimpleEloquent drivers for persistent chat history storage.

**Using Eloquent storage:**
```php
class MyAgent extends Agent
{
    protected $history = 'eloquent';
}
```

Publish and run the migration:
```bash
php artisan vendor:publish --tag=laragent-migrations
php artisan migrate
```

---

### 📋 Structured Output with DataModel

Define type-safe response schemas using DataModel classes for predictable, structured agent responses.

**Create a DataModel:**
```php
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class WeatherResponse extends DataModel
{
    #[Desc('Current temperature in Celsius')]
    public float $temperature;
    
    #[Desc('Weather condition (sunny, cloudy, rainy, etc.)')]
    public string $condition;
    
    #[Desc('Humidity percentage')]
    public int $humidity;
}
```

**Use in your agent:**
```php
class WeatherAgent extends Agent
{
    protected $responseSchema = WeatherResponse::class;
}

// Response is automatically typed
$response = WeatherAgent::ask('What\'s the weather in London?');
$response->temperature;  // 18.5
$response->condition;    // "cloudy"
$response->humidity;     // 72
```

**DataModel supports:**
- Union types with `oneOf` schema generation
- Nested DataModels for complex structures
- Arrays of DataModels
- Enums for constrained values
- Polymorphic discriminator resolution

---

### 🎯 Context Facade

A new fluent API for managing agent context from anywhere in your application.

```php
use LarAgent\Facades\Context;
use App\AiAgents\MyAgent;

// Get all chat keys for an agent
$chatKeys = Context::of(MyAgent::class)->getChatKeys();

// Filter by user
$userChats = Context::of(MyAgent::class)
    ->forUser('user-123')
    ->getChatIdentities();

// Clear all chats for a user
Context::of(MyAgent::class)
    ->forUser('user-123')
    ->clearAllChats();

// Iterate with full agent access
Context::of(MyAgent::class)
    ->forUser('user-123')
    ->each(function ($identity, $agent) {
        $agent->chatHistory()->clear();
    });

// Lightweight access without agent initialization
$keys = Context::named('MyAgent')->getChatKeys();
```

---

### 🆔 Context with Identities

Enhanced session management with support for user-based and group-based agent creation.

```php
// Session-based (existing)
$agent = MyAgent::for('session-123');

// User-based
$agent = MyAgent::forUser(auth()->id());

// With grouping
$agent = MyAgent::for('support-chat')
    ->group('customer-support')
    ->forUser(auth()->id());

// Access identity information
$sessionKey = $agent->getSessionKey();    // 'support-chat'
$userId = $agent->getUserId();            // auth()->id()
$group = $agent->group();                 // 'customer-support'
$fullKey = $agent->getSessionId();        // Full storage key
```

---

### 🧩 Enhanced Tool Parameter Types

Tools now support advanced parameter types including:

- **DataModel parameters** - Complex structured input
- **Enum parameters** - Constrained choice values
- **Union types** - Multiple allowed types with smart resolution
- **Arrays of typed items** - Collections with type validation

```php
class BookFlightTool extends Tool
{
    protected string $name = 'book_flight';
    
    public function handle(
        FlightDetails $details,     // DataModel parameter
        TravelClass $class,         // Enum parameter  
        string|int $passengers      // Union type
    ): BookingConfirmation {
        // Implementation
    }
}
```

---

## 📚 Documentation Updates

- **Migration Guide**: Comprehensive guide for upgrading from v0.8 to v1.0
- **Internal Documentation**: Detailed docs for Chat History, Context, Truncation Strategies, Usage Tracking, and more
- **DataModel Guide**: Complete documentation for structured output features

---

## ⚠️ Breaking Changes

Please refer to the [MIGRATION.md](MIGRATION.md) guide for detailed migration instructions. Key changes include:

- `Message::create()` and `Message::fromArray()` removed - use typed factory methods
- `ToolResultMessage` constructor now requires `toolName` parameter
- `ChatHistory::getMessages()` now returns `MessageArray` instead of array
- Config key `chat_history` renamed to `history` in provider config
- `contextWindowSize` property renamed to `truncationThreshold`

---

## 🙏 Acknowledgments

Special thanks to all contributors who made this release possible:

- [@MaestroError](https://github.com/MaestroError) - Core development and architecture
- [@Yalasev903](https://github.com/Yalasev903) - MCP tool caching and make:agent:tool command
- [@Copilot](https://github.com/apps/copilot-swe-agent) - Truncation strategies and documentation

---

## 📦 Upgrading

```bash
composer update maestroerror/laragent
```

After upgrading, review the [MIGRATION.md](MIGRATION.md) guide and update your code accordingly.

---

## 🔗 Resources

- [Full Documentation](https://docs.laragent.ai)
- [Migration Guide](MIGRATION.md)
- [Changelog](CHANGELOG.md)
- [Discord Community](https://discord.gg/laragent)

---

Happy building with LarAgent v1.0! 🚀
