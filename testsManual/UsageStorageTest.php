<?php

/**
 * Manual Test: Usage Storage Demonstration
 *
 * This test demonstrates how to use the Usage Storage feature in LarAgent.
 * It shows:
 * - Enabling usage tracking on an agent
 * - Making multiple requests that generate usage data
 * - Filtering usage by various criteria
 * - Aggregating usage statistics
 *
 * To run this test, you need a valid API key for your provider.
 */

use LarAgent\Agent;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Tests\TestCase;
use LarAgent\Usage\Models\LaragentUsage;

uses(TestCase::class);

// Test Agent with Usage Tracking Enabled
class UsageTrackingAgent extends Agent
{
    protected $provider = 'openai';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    // Enable usage tracking
    protected $trackUsage = true;

    // Optionally configure usage storage drivers
    // protected $usageStorage = 'database'; // or ['database', 'cache'], etc.

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses very brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

describe('Usage Storage Manual Test', function () {
    beforeEach(function () {
        $apiKey = include __DIR__.'/../openai-api-key.php';

        config()->set('laragent.providers.openai', [
            'label' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $apiKey,
            'driver' => OpenAiDriver::class,
        ]);
    });

    it('tracks usage across multiple requests', function () {
        $agent = UsageTrackingAgent::forUserId('test-user-123');

        echo "\n=== Usage Tracking Demo ===\n\n";

        // Make first request
        echo "Request 1: Asking 'What is 2+2?'\n";
        $response1 = $agent->respond('What is 2+2?');
        echo "Response: $response1\n\n";

        // Make second request
        echo "Request 2: Asking 'What is the capital of France?'\n";
        $response2 = $agent->respond('What is the capital of France?');
        echo "Response: $response2\n\n";

        // Make third request
        echo "Request 3: Asking 'Name 3 programming languages'\n";
        $response3 = $agent->respond('Name 3 programming languages');
        echo "Response: $response3\n\n";

        // Access usage storage
        $usageStorage = $agent->usageStorage();

        expect($usageStorage)->not->toBeNull();

        // Get all usage entries
        $allUsages = $usageStorage->getUsages();

        echo "=== All Usage Entries ===\n";
        echo "Total entries: ".count($allUsages)."\n\n";

        foreach ($allUsages as $index => $usage) {
            echo "Entry ".($index + 1).":\n";
            echo "  - Prompt Tokens: {$usage->promptTokens}\n";
            echo "  - Completion Tokens: {$usage->completionTokens}\n";
            echo "  - Total Tokens: {$usage->totalTokens}\n";
            echo "  - Model: {$usage->model}\n";
            echo "  - Provider: {$usage->provider}\n";
            echo "  - User ID: {$usage->userId}\n";
            echo "  - Agent: {$usage->agent}\n";
            echo "  - Created At: {$usage->createdAt}\n\n";
        }

        // Get total usage
        $totalUsage = $usageStorage->getTotalUsage();

        echo "=== Total Usage Statistics ===\n";
        echo "Total Prompt Tokens: {$totalUsage['prompt_tokens']}\n";
        echo "Total Completion Tokens: {$totalUsage['completion_tokens']}\n";
        echo "Total Tokens: {$totalUsage['total_tokens']}\n\n";

        // Assertions
        expect($allUsages)->toHaveCount(3);
        expect($totalUsage['total_tokens'])->toBeGreaterThan(0);

        echo "✓ All tests passed!\n\n";
    })->skip('Manual test - requires API key');

    it('demonstrates filtering by user', function () {
        // Create agents for different users
        $agent1 = UsageTrackingAgent::forUserId('user-1');
        $agent2 = UsageTrackingAgent::forUserId('user-2');

        echo "\n=== User Filtering Demo ===\n\n";

        // User 1 makes requests
        echo "User 1: Making request\n";
        $agent1->respond('Hello');

        // User 2 makes requests
        echo "User 2: Making request\n";
        $agent2->respond('Hello');
        $agent2->respond('How are you?');

        // Filter by user 1
        $user1Usage = $agent1->usageStorage()->filterByUserId('user-1');

        echo "\nUser 1 Usage:\n";
        echo "  Entries: ".count($user1Usage)."\n";
        echo "  Total Tokens: ".$user1Usage[0]->totalTokens."\n\n";

        // Filter by user 2
        $user2Usage = $agent2->usageStorage()->filterByUserId('user-2');

        echo "User 2 Usage:\n";
        echo "  Entries: ".count($user2Usage)."\n\n";

        expect($user1Usage)->toHaveCount(1);
        expect($user2Usage)->toHaveCount(2);

        echo "✓ Filtering by user works correctly!\n\n";
    })->skip('Manual test - requires API key');

    it('demonstrates filtering by model and provider', function () {
        $agent = UsageTrackingAgent::for('demo-session');

        echo "\n=== Model/Provider Filtering Demo ===\n\n";

        // Make some requests
        $agent->respond('Hello');
        $agent->respond('Test');

        $usageStorage = $agent->usageStorage();

        // Filter by model
        $modelUsage = $usageStorage->filterByModel('gpt-4o-mini');

        echo "Usage for model 'gpt-4o-mini':\n";
        echo "  Entries: ".count($modelUsage)."\n\n";

        // Filter by provider
        $providerUsage = $usageStorage->filterByProvider('openai');

        echo "Usage for provider 'openai':\n";
        echo "  Entries: ".count($providerUsage)."\n\n";

        expect($modelUsage)->toHaveCount(2);
        expect($providerUsage)->toHaveCount(2);

        echo "✓ Filtering by model and provider works!\n\n";
    })->skip('Manual test - requires API key');

    it('demonstrates usage querying via Eloquent model', function () {
        // This test demonstrates how developers can use the LaragentUsage model
        // to query usage data directly from the database

        echo "\n=== Eloquent Model Querying Demo ===\n\n";

        // Example queries (these would work if data exists in DB)
        echo "Example Eloquent queries:\n\n";

        echo "1. Get usage for a specific user:\n";
        echo "   LaragentUsage::byUserId('user-123')->get()\n\n";

        echo "2. Get usage for a specific model:\n";
        echo "   LaragentUsage::byModel('gpt-4')->get()\n\n";

        echo "3. Get usage for a date range:\n";
        echo "   LaragentUsage::byDateRange('2024-01-01', '2024-01-31')->get()\n\n";

        echo "4. Get total usage for a user:\n";
        echo "   LaragentUsage::getTotalUsage(\n";
        echo "       LaragentUsage::byUserId('user-123')\n";
        echo "   )\n\n";

        echo "5. Complex query with multiple filters:\n";
        echo "   LaragentUsage::query()\n";
        echo "       ->byUserId('user-123')\n";
        echo "       ->byModel('gpt-4')\n";
        echo "       ->byProvider('openai')\n";
        echo "       ->byDateRange('2024-01-01', '2024-01-31')\n";
        echo "       ->get()\n\n";

        echo "6. Aggregate total tokens by user:\n";
        echo "   LaragentUsage::selectRaw('user_id, SUM(total_tokens) as total')\n";
        echo "       ->groupBy('user_id')\n";
        echo "       ->get()\n\n";

        expect(true)->toBeTrue();

        echo "✓ Model queries demonstrated!\n\n";
    });
});
