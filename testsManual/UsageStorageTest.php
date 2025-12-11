<?php

/**
 * Manual Test: Usage Storage Demo
 *
 * This test demonstrates how Usage storage works at the Agent level.
 *
 * Prerequisites:
 * - Set up your API key in a openai-api-key.php file
 * - Run: ./vendor/bin/pest testsManual/UsageStorageTest.php
 *
 * The test will:
 * 1. Create an agent with usage tracking enabled
 * 2. Make several API calls
 * 3. Show how usage is tracked and can be queried
 */

use LarAgent\Agent;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $yourApiKey = include __DIR__.'/openai-api-key.php';

    config()->set('laragent.fallback_provider', 'openai');

    config()->set('laragent.providers.openai', [
        'label' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => $yourApiKey,
        'driver' => OpenAiDriver::class,
        'default_context_window' => 128000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 0.9,
        'track_usage' => true,
    ]);
});

// Test agent with usage tracking enabled
class UsageTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $trackUsage = true;

    protected $usageStorage = 'in_memory';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep your responses brief - one word only.';
    }
}

// Test agent with dynamic tracking
class DynamicUsageAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $history = 'in_memory';

    protected $trackUsage = false;

    public function instructions()
    {
        return 'You are a helpful assistant.';
    }
}

it('tracks usage after API calls', function () {
    $agent = UsageTestAgent::forUserId('user_123');

    // Make first API call
    $response1 = $agent->respond('What is 2 + 2? Reply in one word.');
    expect($response1)->toBeString();

    // Make second API call
    $response2 = $agent->respond('What is the capital of France? Reply in one word.');
    expect($response2)->toBeString();

    // Check usage was tracked
    $usage = $agent->getUsage();
    expect($usage)->not->toBeNull();
    expect($usage->count())->toBe(2);
    expect($usage->getTotalPromptTokens())->toBeGreaterThan(0);
    expect($usage->getTotalCompletionTokens())->toBeGreaterThan(0);
    expect($usage->getTotalTokens())->toBeGreaterThan(0);

    // Verify individual records
    foreach ($usage as $record) {
        expect($record->recordId)->not->toBeNull();
        expect($record->agentName)->toBe('UsageTestAgent');
        expect($record->modelName)->toBe('gpt-4o-mini');
        expect($record->providerName)->toBe('openai');
        expect($record->userId)->toBe('user_123');
        expect($record->recordedAt)->not->toBeNull();
        expect($record->promptTokens)->toBeGreaterThan(0);
        expect($record->completionTokens)->toBeGreaterThan(0);
    }
});

it('returns aggregated usage statistics', function () {
    $agent = UsageTestAgent::forUserId('user_456');

    $agent->respond('Say hello');
    $agent->respond('Say goodbye');

    $aggregate = $agent->getUsageAggregate();

    expect($aggregate)->toBeArray();
    expect($aggregate['total_prompt_tokens'])->toBeGreaterThan(0);
    expect($aggregate['total_completion_tokens'])->toBeGreaterThan(0);
    expect($aggregate['total_tokens'])->toBeGreaterThan(0);
    expect($aggregate['record_count'])->toBe(2);
});

it('can filter usage by criteria', function () {
    $agent = UsageTestAgent::forUserId('filter_test_user');

    $agent->respond('Test message');

    // Filter by model
    $filtered = $agent->getUsage(['model_name' => 'gpt-4o-mini']);
    expect($filtered->count())->toBe(1);

    // Filter by user
    $userFiltered = $agent->getUsage(['user_id' => 'filter_test_user']);
    expect($userFiltered->count())->toBe(1);

    // Filter by non-existent criteria
    $empty = $agent->getUsage(['user_id' => 'non_existent_user']);
    expect($empty->count())->toBe(0);
});

it('can group usage by field', function () {
    $agent = UsageTestAgent::forUserId('group_test_user');

    $agent->respond('Test 1');
    $agent->respond('Test 2');

    $grouped = $agent->getUsageGroupedBy('provider_name');

    expect($grouped)->toBeArray();
    expect($grouped)->toHaveKey('openai');
    expect($grouped['openai']['record_count'])->toBe(2);
    expect($grouped['openai']['total_tokens'])->toBeGreaterThan(0);
});

it('tracks usage separately per user', function () {
    $agent1 = UsageTestAgent::forUserId('user_a');
    $agent2 = UsageTestAgent::forUserId('user_b');

    $agent1->respond('Hello from user A');
    $agent2->respond('Hello from user B');

    $usage1 = $agent1->getUsage();
    $usage2 = $agent2->getUsage();

    // Each user should have their own usage records (in-memory storage is separate)
    expect($usage1->count())->toBe(1);
    expect($usage2->count())->toBe(1);
});

it('can enable and disable tracking dynamically', function () {
    $agent = DynamicUsageAgent::for('dynamic_test');

    // Initially disabled
    expect($agent->shouldTrackUsage())->toBeFalse();

    // Enable tracking
    $agent->trackUsage(true);
    expect($agent->shouldTrackUsage())->toBeTrue();

    // Disable tracking
    $agent->trackUsage(false);
    expect($agent->shouldTrackUsage())->toBeFalse();
});

it('returns null for usage methods when tracking is disabled', function () {
    $agent = DynamicUsageAgent::for('disabled_test');

    // Tracking is disabled by default
    expect($agent->shouldTrackUsage())->toBeFalse();

    // Usage methods should return null
    expect($agent->getUsage())->toBeNull();
    expect($agent->getUsageAggregate())->toBeNull();
    expect($agent->getUsageGroupedBy('model_name'))->toBeNull();
});

it('can clear usage records', function () {
    $agent = UsageTestAgent::forUserId('clear_test_user');

    $agent->respond('Test message');
    expect($agent->getUsage()->count())->toBe(1);

    $agent->clearUsage();
    expect($agent->getUsage()->count())->toBe(0);
});
