# Usage Storage

The Usage Storage feature in LarAgent allows you to track and monitor token usage across all AI interactions. This is essential for cost monitoring, usage analytics, and understanding your AI application's resource consumption.

## Key Features

- **Automatic Tracking**: Automatically captures token usage from every AI response
- **Rich Metadata**: Stores usage with identity (user, group, chat), model, provider, agent, and timestamp
- **Flexible Storage**: Use any storage driver (memory, file, cache, database)
- **Powerful Filtering**: Filter usage by user, model, provider, agent, date range
- **Aggregation**: Get total usage statistics across filters
- **Database Support**: Direct Eloquent model access for complex queries

## Quick Start

### Enable Usage Tracking

Simply set `$trackUsage = true` in your Agent class:

```php
use LarAgent\Agent;

class MyAgent extends Agent
{
    protected $trackUsage = true;
    
    // Optional: Configure storage drivers
    // protected $usageStorage = 'database';
    
    public function instructions()
    {
        return 'You are a helpful assistant.';
    }
}
```

### Access Usage Data

```php
$agent = MyAgent::forUserId('user-123');
$agent->respond('Hello!');

// Access usage storage
$usageStorage = $agent->usageStorage();

// Get all usage entries
$usages = $usageStorage->getUsages();

// Get total usage
$total = $usageStorage->getTotalUsage();
echo "Total tokens used: {$total['total_tokens']}";
```

## Configuration

### Storage Drivers

Usage storage uses the same driver configuration as chat history. You can configure it in several ways:

#### 1. Use Default Storage Drivers

If you don't specify `$usageStorage`, it will use `$storage` or the default storage drivers from config:

```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
    // Will use defaultStorageDrivers
}
```

#### 2. Use Built-in Storage

```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
    protected $usageStorage = 'database'; // or 'cache', 'file', 'in_memory'
}
```

#### 3. Custom Drivers

```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
    protected $usageStorage = [
        \LarAgent\Context\Drivers\EloquentStorage::class,
        \LarAgent\Context\Drivers\CacheStorage::class,
    ];
}
```

#### 4. Override Creation Method

```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
    
    public function createUsageStorage()
    {
        // Custom storage creation
        return new \LarAgent\Usage\Storages\UsageStorage(
            $this->context()->getIdentity(),
            [\MyCustomDriver::class]
        );
    }
}
```

## Filtering Usage

The Usage Storage provides powerful filtering capabilities:

### Filter by User

```php
$usageStorage = $agent->usageStorage();
$userUsage = $usageStorage->filterByUserId('user-123');

echo "User total: " . $userUsage->count() . " requests";
```

### Filter by Model

```php
$gpt4Usage = $usageStorage->filterByModel('gpt-4');
$totalTokens = 0;
foreach ($gpt4Usage as $usage) {
    $totalTokens += $usage->totalTokens;
}
```

### Filter by Provider

```php
$openaiUsage = $usageStorage->filterByProvider('openai');
$claudeUsage = $usageStorage->filterByProvider('claude');
```

### Filter by Agent

```php
$agentUsage = $usageStorage->filterByAgent('CustomerSupportAgent');
```

### Filter by Date Range

```php
$monthUsage = $usageStorage->filterByDateRange(
    '2024-01-01',
    '2024-01-31'
);
```

### Chain Filters

```php
$filtered = $usageStorage
    ->filterByUserId('user-123')
    ->filter(function ($usage) {
        return $usage->model === 'gpt-4' && 
               $usage->totalTokens > 1000;
    });
```

## Database Queries

When using database storage, you can query usage directly via the Eloquent model:

### Basic Queries

```php
use LarAgent\Usage\Models\LaragentUsage;

// Get all usage for a user
$usage = LaragentUsage::byUserId('user-123')->get();

// Get usage for a specific model
$usage = LaragentUsage::byModel('gpt-4')->get();

// Get usage for a date range
$usage = LaragentUsage::byDateRange('2024-01-01', '2024-01-31')->get();

// Complex query
$usage = LaragentUsage::query()
    ->byUserId('user-123')
    ->byModel('gpt-4')
    ->byProvider('openai')
    ->byDateRange('2024-01-01', '2024-01-31')
    ->get();
```

### Aggregations

```php
// Total usage for a user
$total = LaragentUsage::getTotalUsage(
    LaragentUsage::byUserId('user-123')
);

// Usage by user
$userStats = LaragentUsage::selectRaw('
    user_id, 
    SUM(total_tokens) as total_tokens,
    COUNT(*) as request_count
')
    ->groupBy('user_id')
    ->get();

// Usage by model
$modelStats = LaragentUsage::selectRaw('
    model, 
    SUM(total_tokens) as total_tokens,
    AVG(total_tokens) as avg_tokens
')
    ->groupBy('model')
    ->orderByDesc('total_tokens')
    ->get();

// Daily usage
$dailyUsage = LaragentUsage::selectRaw('
    DATE(created_at) as date,
    SUM(total_tokens) as total_tokens
')
    ->groupBy('date')
    ->orderBy('date')
    ->get();
```

## Usage Data Structure

Each usage entry contains:

```php
[
    'prompt_tokens' => 100,      // Tokens in the prompt
    'completion_tokens' => 50,   // Tokens in the completion
    'total_tokens' => 150,       // Total tokens used
    'user_id' => 'user-123',     // User identifier (if available)
    'group' => 'group-1',        // Group identifier (if available)
    'chat_name' => 'session-1',  // Chat session name
    'model' => 'gpt-4',          // Model used
    'provider' => 'openai',      // Provider label
    'agent' => 'MyAgent',        // Agent name
    'created_at' => '2024-01-01T00:00:00+00:00', // ISO 8601 timestamp
]
```

## Events

Usage tracking dispatches events at key points:

- `UsageAdding`: Before adding usage to storage
- `UsageAdded`: After usage is added
- `UsageStorageSaving`: Before saving to storage drivers
- `UsageStorageSaved`: After saving to storage drivers
- `UsageStorageLoaded`: After loading from storage drivers

### Listening to Events

```php
use LarAgent\Usage\Events\UsageAdded;

Event::listen(UsageAdded::class, function (UsageAdded $event) {
    $usage = $event->usage;
    
    // Send alert if usage exceeds threshold
    if ($usage->totalTokens > 10000) {
        // Send notification
    }
});
```

## Best Practices

### 1. Use Database Storage for Production

For production environments, use database storage for reliability and query capabilities:

```php
protected $usageStorage = 'database';
```

### 2. Add Indexes for Your Query Patterns

The migration includes common indexes, but add more based on your needs:

```php
Schema::table('laragent_usage', function (Blueprint $table) {
    $table->index(['user_id', 'model', 'created_at']);
});
```

### 3. Archive Old Data

Set up a scheduled job to archive old usage data:

```php
use LarAgent\Usage\Models\LaragentUsage;

// Archive data older than 6 months
$oldDate = now()->subMonths(6);
LaragentUsage::where('created_at', '<', $oldDate)->delete();
```

### 4. Monitor Costs

Create a dashboard to monitor costs:

```php
$dailyCost = LaragentUsage::whereDate('created_at', today())
    ->get()
    ->sum(function ($usage) {
        // Calculate cost based on your pricing
        return ($usage->prompt_tokens * 0.00003) + 
               ($usage->completion_tokens * 0.00006);
    });
```

### 5. Set Usage Limits

Implement usage limits per user:

```php
public function beforeResponse($history, $message)
{
    $usageStorage = $this->usageStorage();
    
    // Get user's monthly usage
    $monthlyUsage = $usageStorage
        ->filterByUserId($this->getUserId())
        ->filterByDateRange(now()->startOfMonth(), now());
    
    $totalTokens = $monthlyUsage->sum('totalTokens');
    
    if ($totalTokens > 100000) {
        throw new \Exception('Monthly usage limit exceeded');
    }
}
```

## Migration

The usage table migration is located at:
```
src/Usage/Database/migrations/create_laragent_usage_table.php
```

Run it with your Laravel migrations:
```bash
php artisan migrate
```

## Testing

Comprehensive tests are available:

### Unit Tests
```bash
vendor/bin/pest tests/LarAgent/Usage/UsageStorageTest.php
```

### Manual Tests (requires API key)
```bash
vendor/bin/pest testsManual/UsageStorageTest.php
```

## See Also

- [Storage Abstraction Documentation](../Context/README.md)
- [Agent Configuration](../Agent.md)
- [Events Documentation](../Events/README.md)
