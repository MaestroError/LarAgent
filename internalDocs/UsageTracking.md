# Usage Tracking Configuration and Usage

Usage Tracking in LarAgent allows you to monitor and store token consumption across all AI interactions. It tracks prompt tokens, completion tokens, and total tokens for each API call.

## Overview

Usage tracking automatically captures usage data from LLM responses and stores them using the same storage abstraction system as chat history. This enables you to:

- Monitor API costs across agents and users
- Analyze usage patterns
- Implement rate limiting or quotas
- Generate usage reports

## Configuration

### Global Configuration (config/laragent.php)

```php
return [
    /**
     * Enable usage tracking globally for all agents.
     * Can be overridden per-provider or per-agent via $trackUsage property.
     * Priority: Agent property > Provider config > Global config
     */
    'track_usage' => false,

    /**
     * Default storage drivers for usage tracking.
     * If not set, uses 'default_storage' configuration.
     */
    'default_usage_storage' => null,
];
```

### Per-Provider Configuration

```php
// config/laragent.php
'providers' => [
    'default' => [
        'label' => 'openai',
        'api_key' => env('OPENAI_API_KEY'),
        'driver' => OpenAiDriver::class,
        // Enable tracking for this provider
        'track_usage' => true,
        // Optional: custom storage for this provider
        'usage_storage' => [
            \LarAgent\Context\Drivers\CacheStorage::class,
        ],
    ],
],
```

### Per-Agent Configuration

```php
use LarAgent\Agent;

class MyAgent extends Agent
{
    // Enable usage tracking for this agent
    protected $trackUsage = true;

    // Optional: Specify usage storage driver(s)
    protected $usageStorage = 'database';  // or array of driver classes

    public function instructions()
    {
        return 'You are a helpful assistant.';
    }
}
```

### Built-in Usage Storage Aliases

| Alias | Driver Class |
|-------|-------------|
| `'in_memory'` | `InMemoryStorage::class` |
| `'session'` | `SessionStorage::class` |
| `'cache'` | `CacheStorage::class` |
| `'file'` | `FileStorage::class` |
| `'database'` | `EloquentUsageDriver::class` |
| `'database-simple'` | `SimpleEloquentStorage::class` |

## Usage

### Basic Usage

```php
// Create an agent with usage tracking enabled
$agent = MyAgent::forUserId('user-123');

// Make API calls
$response = $agent->respond('Hello!');
$response = $agent->respond('Tell me a joke.');

// Get all usage records
$usage = $agent->getUsage();
```

### Checking if Tracking is Enabled

```php
$isTracking = $agent->shouldTrackUsage();
```

### Dynamic Enable/Disable

```php
// Enable tracking
$agent->trackUsage(true);

// Disable tracking
$agent->trackUsage(false);
```

### Accessing Usage Data

```php
// Get all usage records
$usage = $agent->getUsage();

if ($usage !== null) {
    // Iterate over records
    foreach ($usage as $record) {
        echo "Prompt tokens: {$record->promptTokens}\n";
        echo "Completion tokens: {$record->completionTokens}\n";
        echo "Total tokens: {$record->totalTokens}\n";
        echo "Model: {$record->modelName}\n";
        echo "Provider: {$record->providerName}\n";
        echo "Recorded at: {$record->recordedAt}\n";
    }
    
    // Get totals
    echo "Total prompt tokens: " . $usage->getTotalPromptTokens();
    echo "Total completion tokens: " . $usage->getTotalCompletionTokens();
    echo "Total tokens: " . $usage->getTotalTokens();
}
```

### Filtering Usage Data

```php
// Filter by various criteria
$filtered = $agent->getUsage([
    'model_name' => 'gpt-4o-mini',
    'provider_name' => 'openai',
    'user_id' => 'user-123',
    'agent_name' => 'MyAgent',
    'date' => '2024-01-15',
    // Or date range:
    // 'date_from' => '2024-01-01',
    // 'date_to' => '2024-01-31',
]);
```

### Aggregating Usage

```php
// Get aggregated statistics
$aggregate = $agent->getUsageAggregate();

// Returns array with:
// [
//     'total_prompt_tokens' => 1000,
//     'total_completion_tokens' => 500,
//     'total_tokens' => 1500,
//     'record_count' => 10,
// ]

// With filters
$monthlyAggregate = $agent->getUsageAggregate([
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31',
]);
```

### Grouping Usage Data

```php
// Group by provider
$byProvider = $agent->getUsageGroupedBy('provider_name');
// [
//     'openai' => [
//         'total_prompt_tokens' => 800,
//         'total_completion_tokens' => 400,
//         'total_tokens' => 1200,
//         'record_count' => 8,
//     ],
//     'anthropic' => [
//         'total_prompt_tokens' => 200,
//         'total_completion_tokens' => 100,
//         'total_tokens' => 300,
//         'record_count' => 2,
//     ],
// ]

// Available grouping fields:
// - 'agent_name'
// - 'user_id'
// - 'model_name'
// - 'provider_name'
// - 'group'
```

### Clearing Usage Records

```php
// Clear all usage records for this identity
$agent->clearUsage();
```

### Getting Usage Identities

```php
// Get all tracked usage identities for this agent class
$identities = $agent->getUsageIdentities();
```

## UsageRecord Properties

Each usage record contains:

| Property | Type | Description |
|----------|------|-------------|
| `recordId` | `string` | Unique identifier |
| `agentName` | `string` | Name of the agent |
| `userId` | `?string` | User ID (if applicable) |
| `chatName` | `?string` | Chat/session name |
| `group` | `?string` | Group name |
| `modelName` | `string` | AI model used |
| `providerName` | `string` | Provider name |
| `promptTokens` | `int` | Input tokens |
| `completionTokens` | `int` | Output tokens |
| `totalTokens` | `int` | Total tokens |
| `recordedAt` | `string` | Timestamp |

## Storage Priority

Usage storage is resolved in the following order:

1. **Agent property** (`$usageStorage`)
2. **Provider config** (`usage_storage` in provider array)
3. **Global config** (`default_usage_storage`)
4. **Fallback** (`default_storage` configuration)

## Database Storage (Eloquent)

For persistent storage with query capabilities, use the Eloquent driver:

```php
class MyAgent extends Agent
{
    protected $trackUsage = true;
    protected $usageStorage = 'database';
}
```

This requires running the migration:

```bash
php artisan migrate
```

The migration creates the `laragent_usage` table with columns for all usage record properties.

## Best Practices

1. **Enable tracking for production monitoring:**
   ```php
   // config/laragent.php
   'track_usage' => env('LARAGENT_TRACK_USAGE', true),
   ```

2. **Use database storage for persistent analytics:**
   ```php
   protected $usageStorage = 'database';
   ```

3. **Implement periodic cleanup for non-database storage:**
   ```php
   // Clear old records periodically
   $agent->clearUsage();
   ```

4. **Monitor costs per user:**
   ```php
   $userUsage = $agent->getUsageAggregate(['user_id' => $userId]);
   $estimatedCost = calculateCost($userUsage['total_tokens']);
   ```

5. **Use grouping for dashboards:**
   ```php
   // Dashboard showing usage by model
   $byModel = $agent->getUsageGroupedBy('model_name');
   ```

## Example: Cost Monitoring

```php
class CostAwareAgent extends Agent
{
    protected $trackUsage = true;
    protected $usageStorage = 'database';

    public function respond(?string $message = null): string|array|MessageInterface
    {
        $response = parent::respond($message);
        
        // Check usage after response
        $usage = $this->getUsageAggregate();
        if ($usage['total_tokens'] > 100000) {
            Log::warning('High token usage detected', $usage);
        }
        
        return $response;
    }
}
```

## Returns When Tracking is Disabled

When usage tracking is disabled, usage methods return `null`:

```php
$agent->trackUsage(false);

$usage = $agent->getUsage(); // null
$aggregate = $agent->getUsageAggregate(); // null
$grouped = $agent->getUsageGroupedBy('model_name'); // null
```
