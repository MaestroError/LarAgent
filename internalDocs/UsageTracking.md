# Usage Tracking Configuration and Usage

The Usage Tracking system in LarAgent monitors and stores token consumption metrics from AI model responses. It provides insights into API usage, costs, and helps with optimization.

## Overview

Usage tracking in LarAgent automatically captures:

- **Prompt tokens**: Tokens used in the input/request
- **Completion tokens**: Tokens used in the output/response
- **Total tokens**: Combined prompt + completion tokens
- **Timestamps**: When each response was generated
- **Model and provider information**: Which model and provider generated the response
- **Agent and user context**: Which agent and user triggered the response

## Configuration Levels

### 1. Global Configuration (config/laragent.php)

Enable usage tracking for all agents globally:

```php
// config/laragent.php

return [
    /**
     * Enable usage tracking globally for all agents.
     * Can be overridden per-provider or per-agent.
     * Priority: Agent property > Provider config > Global config
     */
    'track_usage' => false,

    /**
     * Default storage drivers for usage tracking.
     * If null, uses 'default_storage' configuration.
     */
    'default_usage_storage' => null,

    // Or specify explicit drivers:
    // 'default_usage_storage' => [
    //     \LarAgent\Context\Drivers\CacheStorage::class,
    //     \LarAgent\Context\Drivers\FileStorage::class,
    // ],
];
```

### 2. Per-Provider Configuration (config/laragent.php)

Configure usage tracking for specific providers:

```php
// config/laragent.php

return [
    'providers' => [
        'default' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            
            // Enable usage tracking for this provider
            'track_usage' => true,
            
            // Provider-specific usage storage
            'usage_storage' => [
                \LarAgent\Usage\Drivers\EloquentUsageDriver::class,
            ],
        ],
        
        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            
            // Disable tracking for this provider
            'track_usage' => false,
        ],
    ],
];
```

### 3. Per-Agent Property Configuration

Set usage tracking directly in your agent class:

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;

class AnalyticsAgent extends Agent
{
    protected $instructions = 'You are an analytics assistant.';
    
    /**
     * Enable usage tracking for this agent.
     * Set to true/false to override config, or null to use config.
     */
    protected $trackUsage = true;
    
    /**
     * Storage drivers for usage data.
     * Can be array of driver classes or string alias.
     */
    protected $usageStorage = [
        CacheStorage::class,
    ];
}
```

Using string aliases:

```php
class AnalyticsAgent extends Agent
{
    protected $trackUsage = true;
    
    // Use database storage for persistent tracking
    protected $usageStorage = 'database';
}
```

Available aliases:
- `'in_memory'` - `InMemoryStorage` (no persistence)
- `'session'` - `SessionStorage`
- `'cache'` - `CacheStorage`
- `'file'` - `FileStorage`
- `'database'` - `EloquentUsageDriver` (requires migration)
- `'database-simple'` - `SimpleEloquentStorage`

### 4. Per-Agent Method Override

Override methods for complete control:

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Usage\UsageStorage;

class CustomTrackingAgent extends Agent
{
    protected $instructions = 'Custom tracking agent.';
    
    /**
     * Override to customize tracking behavior.
     */
    public function shouldTrackUsage(): bool
    {
        // Custom logic - e.g., only track for production
        return app()->environment('production');
    }
    
    /**
     * Create a custom usage storage instance.
     */
    public function createUsageStorage(): UsageStorage
    {
        return new UsageStorage(
            $this->context()->getIdentity(),
            $this->usageStorageDrivers(),
            $this->model(),
            $this->providerName
        );
    }
}
```

## Runtime Configuration

### Enable/Disable Tracking Dynamically

```php
// Enable tracking for a specific request
$agent = SupportAgent::for('session-123')
    ->trackUsage(true)
    ->respond('Hello!');

// Disable tracking
$agent->trackUsage(false);
```

## Database Setup for Usage Tracking

To persist usage data in a database, publish and run the migration:

```bash
# Publish the usage storage migration
php artisan la:publish usage-storage

# Run the migration
php artisan migrate
```

This creates the `laragent_usage` table with columns for all tracked metrics.

## Working with Usage Data

### Accessing Usage Storage

```php
// Get the usage storage instance
$usageStorage = $agent->usageStorage();

// Returns null if tracking is disabled
if ($usageStorage === null) {
    // Tracking is disabled
}
```

### Getting Usage Records

```php
// Get all usage records for this agent/user
$usage = $agent->getUsage();

// Get usage with filters
$usage = $agent->getUsage([
    'model_name' => 'gpt-4',
    'date' => '2024-01-15',
]);

// Filter options:
// - 'agent_name': Filter by agent class name
// - 'user_id': Filter by user ID (null for non-user sessions)
// - 'group': Filter by group
// - 'model_name': Filter by model name
// - 'provider_name': Filter by provider label
// - 'date': Filter by specific date (Y-m-d)
// - 'date_from': Filter from date
// - 'date_to': Filter to date
```

### Aggregating Usage Statistics

```php
// Get aggregate statistics
$stats = $agent->getUsageAggregate();
// Returns:
// [
//     'total_prompt_tokens' => 1500,
//     'total_completion_tokens' => 800,
//     'total_tokens' => 2300,
//     'request_count' => 10,
// ]

// Aggregate with filters
$stats = $agent->getUsageAggregate([
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31',
]);
```

### Grouping Usage Data

```php
// Group usage by model
$byModel = $agent->getUsageGroupedBy('model_name');
// Returns:
// [
//     'gpt-4' => ['total_tokens' => 1500, 'request_count' => 5],
//     'gpt-3.5-turbo' => ['total_tokens' => 800, 'request_count' => 10],
// ]

// Group by provider
$byProvider = $agent->getUsageGroupedBy('provider_name');

// Group by agent
$byAgent = $agent->getUsageGroupedBy('agent_name');

// Group by user
$byUser = $agent->getUsageGroupedBy('user_id');

// With filters
$byModel = $agent->getUsageGroupedBy('model_name', [
    'date_from' => '2024-01-01',
]);
```

### Getting Usage Identities

```php
// Get all tracked usage identities for this agent class
$identities = $agent->getUsageIdentities();

foreach ($identities as $identity) {
    echo "User: " . $identity->getUserId();
    echo "Chat: " . $identity->getChatName();
}
```

### Clearing Usage Data

```php
// Clear all usage records for this identity
$agent->clearUsage();
```

## Usage Data Structure

Each usage record contains:

```php
[
    'agent_name' => 'SupportAgent',
    'user_id' => 'user-123',        // null for non-user sessions
    'group' => null,                 // Optional group identifier
    'model_name' => 'gpt-4',
    'provider_name' => 'openai',
    'prompt_tokens' => 150,
    'completion_tokens' => 75,
    'total_tokens' => 225,
    'recorded_at' => '2024-01-15T10:30:00Z',
]
```

## Usage Events

The usage data is tracked automatically through the `afterResponse` hook. You can listen to events for custom behavior:

```php
// In a service provider

use LarAgent\Events\Agent\AfterResponse;

Event::listen(AfterResponse::class, function ($event) {
    $message = $event->message;
    
    if (method_exists($message, 'getUsage')) {
        $usage = $message->getUsage();
        if ($usage) {
            Log::info('API Usage', [
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'total_tokens' => $usage->totalTokens,
            ]);
        }
    }
});
```

## Real-World Scenario: Cost Monitoring Dashboard

### Requirements
- Track all API usage across the application
- Generate daily/monthly reports
- Alert when usage exceeds thresholds
- Support multiple AI providers

### Implementation

#### 1. Enable Global Tracking

```php
// config/laragent.php

return [
    'track_usage' => true,
    
    'default_usage_storage' => [
        \LarAgent\Usage\Drivers\EloquentUsageDriver::class,
    ],
];
```

#### 2. Create Analytics Service

```php
<?php

namespace App\Services;

use LarAgent\Facades\Context;
use App\AiAgents\SupportAgent;
use App\AiAgents\CodeAssistant;
use Carbon\Carbon;

class UsageAnalyticsService
{
    protected array $agents = [
        SupportAgent::class,
        CodeAssistant::class,
    ];
    
    /**
     * Get total usage across all agents for a date range.
     */
    public function getTotalUsage(string $from, string $to): array
    {
        $totals = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'request_count' => 0,
        ];
        
        foreach ($this->agents as $agentClass) {
            $agent = $agentClass::make();
            $stats = $agent->getUsageAggregate([
                'date_from' => $from,
                'date_to' => $to,
            ]);
            
            if ($stats) {
                $totals['prompt_tokens'] += $stats['total_prompt_tokens'] ?? 0;
                $totals['completion_tokens'] += $stats['total_completion_tokens'] ?? 0;
                $totals['total_tokens'] += $stats['total_tokens'] ?? 0;
                $totals['request_count'] += $stats['request_count'] ?? 0;
            }
        }
        
        return $totals;
    }
    
    /**
     * Get usage breakdown by provider.
     */
    public function getUsageByProvider(): array
    {
        $byProvider = [];
        
        foreach ($this->agents as $agentClass) {
            $agent = $agentClass::make();
            $grouped = $agent->getUsageGroupedBy('provider_name');
            
            if ($grouped) {
                foreach ($grouped as $provider => $stats) {
                    if (!isset($byProvider[$provider])) {
                        $byProvider[$provider] = [
                            'total_tokens' => 0,
                            'request_count' => 0,
                        ];
                    }
                    $byProvider[$provider]['total_tokens'] += $stats['total_tokens'] ?? 0;
                    $byProvider[$provider]['request_count'] += $stats['request_count'] ?? 0;
                }
            }
        }
        
        return $byProvider;
    }
    
    /**
     * Get daily usage for the last N days.
     */
    public function getDailyUsage(int $days = 30): array
    {
        $usage = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayTotal = 0;
            
            foreach ($this->agents as $agentClass) {
                $agent = $agentClass::make();
                $stats = $agent->getUsageAggregate(['date' => $date]);
                $dayTotal += $stats['total_tokens'] ?? 0;
            }
            
            $usage[$date] = $dayTotal;
        }
        
        return $usage;
    }
    
    /**
     * Estimate cost based on token pricing.
     */
    public function estimateCost(string $from, string $to): array
    {
        // Example pricing (per 1K tokens)
        $pricing = [
            'openai' => [
                'gpt-4' => ['prompt' => 0.03, 'completion' => 0.06],
                'gpt-3.5-turbo' => ['prompt' => 0.001, 'completion' => 0.002],
            ],
        ];
        
        $costs = [];
        
        foreach ($this->agents as $agentClass) {
            $agent = $agentClass::make();
            $usage = $agent->getUsage([
                'date_from' => $from,
                'date_to' => $to,
            ]);
            
            if ($usage) {
                foreach ($usage as $record) {
                    $provider = $record->provider_name ?? 'unknown';
                    $model = $record->model_name ?? 'unknown';
                    
                    if (isset($pricing[$provider][$model])) {
                        $rates = $pricing[$provider][$model];
                        $cost = (($record->prompt_tokens / 1000) * $rates['prompt']) +
                                (($record->completion_tokens / 1000) * $rates['completion']);
                        
                        $key = "{$provider}:{$model}";
                        if (!isset($costs[$key])) {
                            $costs[$key] = 0;
                        }
                        $costs[$key] += $cost;
                    }
                }
            }
        }
        
        return $costs;
    }
}
```

#### 3. Dashboard Controller

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Services\UsageAnalyticsService;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function __construct(
        protected UsageAnalyticsService $analytics
    ) {}
    
    public function dashboard(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));
        
        return response()->json([
            'total_usage' => $this->analytics->getTotalUsage($from, $to),
            'by_provider' => $this->analytics->getUsageByProvider(),
            'daily_usage' => $this->analytics->getDailyUsage(30),
            'estimated_cost' => $this->analytics->estimateCost($from, $to),
        ]);
    }
    
    public function userUsage(string $userId)
    {
        $agents = [
            \App\AiAgents\SupportAgent::class,
            \App\AiAgents\CodeAssistant::class,
        ];
        
        $usage = [];
        
        foreach ($agents as $agentClass) {
            $agent = $agentClass::forUserId($userId);
            $usage[$agentClass] = $agent->getUsageAggregate();
        }
        
        return response()->json($usage);
    }
}
```

#### 4. Usage Alert Job

```php
<?php

namespace App\Jobs;

use App\Services\UsageAnalyticsService;
use App\Notifications\UsageThresholdExceeded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckUsageThresholds implements ShouldQueue
{
    use Queueable;
    
    protected int $dailyThreshold = 1000000; // 1M tokens
    
    public function handle(UsageAnalyticsService $analytics)
    {
        $today = now()->format('Y-m-d');
        $usage = $analytics->getTotalUsage($today, $today);
        
        if ($usage['total_tokens'] > $this->dailyThreshold) {
            // Send alert
            $admin = \App\Models\User::find(1);
            $admin->notify(new UsageThresholdExceeded($usage));
        }
    }
}
```

### Schedule the Alert

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    $schedule->job(new CheckUsageThresholds)->hourly();
}
```
