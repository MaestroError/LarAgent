<?php

/**
 * Manual Test: Truncation Strategy Demo
 *
 * This test demonstrates how truncation strategies work with real API calls.
 * Messages are pre-populated in history, then a real API call triggers truncation.
 *
 * Prerequisites:
 * - Set OPENAI_API_KEY in openai-api-key.php
 * - Run: ./vendor/bin/pest testsManual/TruncationStrategyTest.php
 *
 * The test will:
 * 1. Create an agent with truncation enabled
 * 2. Pre-populate chat history with fake messages
 * 3. Send a real message which triggers prepareAgent and truncation
 * 4. Show different truncation strategies
 */

use LarAgent\Agent;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Message;
use LarAgent\Tests\TestCase;
use LarAgent\Usage\DataModels\Usage;

uses(TestCase::class);

beforeEach(function () {
    // Get API key from environment variable
    $yourApiKey = include 'openai-api-key.php';

    if (empty($yourApiKey)) {
        $this->markTestSkipped('OPENAI_API_KEY not set in openai-api-key.php');
    }

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

    protected $storage = [
        \LarAgent\Context\Drivers\InMemoryStorage::class,
    ];

    protected $history = 'in_memory';

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SimpleTruncationStrategy([
            'keep_messages' => 3,
            'preserve_system' => true,
        ]);
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant. Keep your responses brief (one sentence max).';
    }
}

/**
 * Helper to create a fake assistant message with usage data.
 * The totalTokens should represent cumulative conversation tokens at that point.
 */
function createAssistantMessage(string $content, int $totalTokens): \LarAgent\Messages\AssistantMessage
{
    $message = Message::assistant($content);
    // totalTokens represents the full prompt + completion for that API call
    // In real API calls, this grows as the conversation history increases
    $message->setUsage(new Usage(
        promptTokens: (int) ($totalTokens * 0.8),  // Approximate prompt portion
        completionTokens: (int) ($totalTokens * 0.2),  // Approximate completion portion
        totalTokens: $totalTokens
    ));

    return $message;
}

test('simple truncation strategy - prepopulate history then send message', function () {
    $agent = SimpleTruncationTestAgent::for('simple-truncation-test');

    echo "\n=== Simple Truncation Strategy Test ===\n";
    echo "Context Window: {$agent->getContextWindowSize()} tokens\n";
    echo "Keep Messages: 3\n\n";

    // Pre-populate with fake conversation history
    // Each message's totalTokens represents cumulative token count at that point
    // Context window is 5000, so we exceed it to trigger truncation
    $fakeHistory = [
        ['What is 2+2?', 'The answer is 4.', 500],
        ['What is the capital of France?', 'Paris is the capital.', 1200],
        ['What color is the sky?', 'The sky is blue.', 2000],
        ['How many days in a week?', 'There are 7 days.', 3000],
        ['What is the largest planet?', 'Jupiter is largest.', 4200],
        ['What is water made of?', 'H2O molecules.', 5500],  // Exceeds 5000 context window
    ];

    echo "Pre-populating chat history with fake messages...\n";
    echo "Last message has totalTokens=5500 which exceeds context window of 5000\n\n";

    foreach ($fakeHistory as $index => [$question, $answer, $totalTokens]) {
        $agent->chatHistory()->addMessage(Message::user($question));
        $agent->chatHistory()->addMessage(createAssistantMessage($answer, $totalTokens));
        echo 'Added Q'.($index + 1).": totalTokens={$totalTokens}\n";
    }

    $countBeforeTruncation = $agent->chatHistory()->getMessages()->count();
    echo "\nMessages before API call: {$countBeforeTruncation}\n";
    echo "Sending real API request (triggers truncation in prepareAgent)...\n\n";

    // This will trigger prepareAgent() which calls applyTruncationIfNeeded()
    $response = $agent->respond('What is 1+1?');

    echo "Response: {$response}\n\n";

    $countAfterTruncation = $agent->chatHistory()->getMessages()->count();
    echo "Messages after truncation + new exchange: {$countAfterTruncation}\n";
    echo "With keep_messages=3: system + 3 recent messages kept, then new pair added\n";

    // Should be significantly less than original (12) + 2 new = 14
    // With keep_messages=3 and preserve_system=true: ~system + 3 + 2 new = ~6-8
    expect($countAfterTruncation)->toBeLessThan($countBeforeTruncation);
});

test('truncation preserves system messages', function () {
    $agent = SimpleTruncationTestAgent::for('system-message-test');

    echo "\n=== System Message Preservation Test ===\n\n";

    // First, add a system message to the history (simulating existing chat with system)
    $agent->chatHistory()->addMessage(Message::system('You are a helpful assistant from pre-existing chat.'));

    // Pre-populate with many fake messages with high token counts
    $totalTokens = 500;
    for ($i = 1; $i <= 8; $i++) {
        $agent->chatHistory()->addMessage(Message::user("Question {$i}?"));
        $totalTokens += 700;  // Each exchange adds ~700 tokens
        $agent->chatHistory()->addMessage(createAssistantMessage("Answer {$i}.", $totalTokens));
    }
    // Final totalTokens = 500 + 8*700 = 6100 (exceeds 5000 context window)

    echo "Pre-populated with system + 8 pairs (17 messages), last totalTokens={$totalTokens}\n";
    echo 'Messages before: '.$agent->chatHistory()->getMessages()->count()."\n";
    echo "Sending real API request...\n\n";

    $response = $agent->respond('Final question?');

    echo "Response: {$response}\n\n";

    $messages = $agent->chatHistory()->getMessages();
    $firstMessage = $messages->all()[0] ?? null;

    echo 'Messages after: '.$messages->count()."\n";
    echo 'First message role: '.($firstMessage ? $firstMessage->getRole() : 'none')."\n";

    // System message should be preserved by truncation strategy (preserve_system=true)
    expect($firstMessage)->not->toBeNull();
    expect($firstMessage->getRole())->toBe('system');
    echo "✓ System message preserved!\n";
});

test('truncation event is dispatched', function () {
    $agent = SimpleTruncationTestAgent::for('truncation-event-test');

    echo "\n=== Truncation Event Test ===\n\n";

    $eventDispatched = false;
    $messagesInEvent = null;

    // Listen for truncation event (note the correct namespace)
    Event::listen(\LarAgent\Events\ChatHistory\ChatHistoryTruncated::class, function ($event) use (&$eventDispatched, &$messagesInEvent) {
        $eventDispatched = true;
        $messagesInEvent = $event->messages;
        echo "📢 ChatHistoryTruncated event received!\n";
        echo '   Messages in event: '.$event->messages->count()."\n";
    });

    // Pre-populate with many fake messages with high token counts
    $totalTokens = 500;
    for ($i = 1; $i <= 10; $i++) {
        $agent->chatHistory()->addMessage(Message::user("Question {$i}?"));
        $totalTokens += 600;
        $agent->chatHistory()->addMessage(createAssistantMessage("Answer {$i}.", $totalTokens));
    }
    // Final totalTokens = 500 + 10*600 = 6500 (exceeds 5000)

    $countBefore = $agent->chatHistory()->getMessages()->count();
    echo "Pre-populated with 10 pairs ({$countBefore} messages), last totalTokens={$totalTokens}\n";
    echo "Sending real API request to trigger truncation...\n\n";

    $response = $agent->respond('Trigger truncation please.');

    echo "\nResponse: {$response}\n\n";

    $countAfter = $agent->chatHistory()->getMessages()->count();
    echo "Messages after: {$countAfter}\n";

    expect($eventDispatched)->toBeTrue();
    expect($countAfter)->toBeLessThan($countBefore);
});
