<?php

/**
 * Manual Test: Truncation Strategy Demo
 *
 * This test demonstrates how truncation strategies work with real API calls.
 *
 * Prerequisites:
 * - Set up your API key in a openai-api-key.php file
 * - Run: ./vendor/bin/pest testsManual/TruncationStrategyTest.php
 *
 * The test will:
 * 1. Create an agent with truncation enabled
 * 2. Make several API calls to build up history
 * 3. Demonstrate truncation when context window is approached
 * 4. Show different truncation strategies
 */

use LarAgent\Agent;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Context\Truncation\TokenBasedTruncationStrategy;
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
        'enable_truncation' => true,
    ]);
});

// Test agent with simple truncation strategy
class SimpleTruncationTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $trackUsage = true;

    protected $enableTruncation = true;

    protected $contextWindowSize = 5000; // Small window to trigger truncation

    protected $storage = 'in_memory';

    protected $history = 'in_memory';

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SimpleTruncationStrategy([
            'keep_messages' => 3,
            'preserve_system' => true,
        ]);
    }

    protected function instructions(): string
    {
        return 'You are a helpful assistant. Keep your responses brief.';
    }
}

// Test agent with token-based truncation
class TokenBasedTruncationTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $trackUsage = true;

    protected $enableTruncation = true;

    protected $contextWindowSize = 5000;

    protected $storage = 'in_memory';

    protected $history = 'in_memory';

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new TokenBasedTruncationStrategy([
            'target_percentage' => 0.7,
            'preserve_system' => true,
        ]);
    }

    protected function instructions(): string
    {
        return 'You are a helpful assistant. Keep your responses brief.';
    }
}

test('simple truncation strategy with real API', function () {
    $agent = SimpleTruncationTestAgent::for('simple-truncation-test');

    echo "\n=== Simple Truncation Strategy Test ===\n";
    echo "Context Window: {$agent->getContextWindowSize()} tokens\n";
    echo "Keep Messages: 3\n\n";

    // Make several API calls to build up history
    echo "Making 5 API calls to build up conversation history...\n\n";

    $questions = [
        'What is 2+2?',
        'What is the capital of France?',
        'What color is the sky?',
        'How many days in a week?',
        'What is the largest planet?',
    ];

    foreach ($questions as $index => $question) {
        echo "Q".($index + 1).": {$question}\n";
        $response = $agent->respond($question);
        echo "A".($index + 1).": {$response}\n\n";

        // Show current message count
        $messageCount = $agent->chatHistory()->getMessages()->count();
        echo "Current message count: {$messageCount}\n";

        // Show usage if available
        $lastMessage = $agent->chatHistory()->getLastMessage();
        if (method_exists($lastMessage, 'getUsage') && $lastMessage->getUsage()) {
            $usage = $lastMessage->getUsage();
            echo "Tokens used: {$usage->totalTokens}\n";
        }
        echo "---\n\n";
    }

    echo "Final message count: ".$agent->chatHistory()->getMessages()->count()."\n";
    echo "Note: With keep_messages=3, we expect around 4-5 messages (system + 3 recent)\n";

    expect($agent->chatHistory()->getMessages()->count())->toBeLessThanOrEqual(6);
})->skip('Manual test - requires API key');

test('token-based truncation strategy with real API', function () {
    $agent = TokenBasedTruncationTestAgent::for('token-truncation-test');

    echo "\n=== Token-Based Truncation Strategy Test ===\n";
    echo "Context Window: {$agent->getContextWindowSize()} tokens\n";
    echo "Target: 70% of window\n\n";

    echo "Making API calls until truncation occurs...\n\n";

    $questions = [
        'Explain what PHP is.',
        'What is Laravel?',
        'What are design patterns?',
        'Explain MVC architecture.',
        'What is dependency injection?',
    ];

    foreach ($questions as $index => $question) {
        echo "Q".($index + 1).": {$question}\n";
        $response = $agent->respond($question);
        echo "A".($index + 1).": ".substr($response, 0, 100)."...\n\n";

        $messageCount = $agent->chatHistory()->getMessages()->count();
        echo "Current message count: {$messageCount}\n";

        // Show usage if available
        $lastMessage = $agent->chatHistory()->getLastMessage();
        if (method_exists($lastMessage, 'getUsage') && $lastMessage->getUsage()) {
            $usage = $lastMessage->getUsage();
            $targetTokens = (int) ($agent->getContextWindowSize() * 0.7);
            echo "Tokens used: {$usage->totalTokens} / Target: {$targetTokens}\n";
            
            if ($usage->totalTokens > $targetTokens) {
                echo "⚠️  Above target - truncation should occur on next request\n";
            }
        }
        echo "---\n\n";
    }

    echo "Final message count: ".$agent->chatHistory()->getMessages()->count()."\n";

    expect($agent->chatHistory()->getMessages()->count())->toBeGreaterThan(0);
})->skip('Manual test - requires API key');

test('truncation maintains system messages', function () {
    $agent = SimpleTruncationTestAgent::for('system-message-test');

    echo "\n=== System Message Preservation Test ===\n\n";

    // Make several API calls
    $questions = ['Question 1?', 'Question 2?', 'Question 3?', 'Question 4?'];

    foreach ($questions as $question) {
        $agent->respond($question);
    }

    $messages = $agent->chatHistory()->getMessages();
    $firstMessage = $messages->toArray()[0] ?? null;

    echo "First message role: ".($firstMessage ? $firstMessage->getRole() : 'none')."\n";
    echo "Total messages: ".$messages->count()."\n";

    if ($firstMessage && $firstMessage->getRole() === 'system') {
        echo "✓ System message preserved!\n";
        expect($firstMessage->getRole())->toBe('system');
    }
})->skip('Manual test - requires API key');
