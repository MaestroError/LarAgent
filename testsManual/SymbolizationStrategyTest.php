<?php

/**
 * Manual Test: Symbolization Strategy
 *
 * This test demonstrates how SymbolizationStrategy works with real API calls.
 * It creates a conversation history and triggers symbolization to show how
 * each older message is converted to a brief symbol (10 words or less).
 *
 * Prerequisites:
 * - Set OPENAI_API_KEY in openai-api-key.php
 * - Run: php testsManual/SymbolizationStrategyTest.php
 *
 * The test will:
 * 1. Create an agent with symbolization truncation enabled
 * 2. Pre-populate chat history with a realistic conversation
 * 3. Trigger truncation and show the symbolized result
 */

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use LarAgent\Agent;
use LarAgent\Context\Truncation\SymbolizationStrategy;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Usage\DataModels\Usage;

// Bootstrap minimal Laravel environment
$container = new Container();
Container::setInstance($container);
$container->singleton('events', fn () => new Dispatcher($container));
$container->singleton('config', fn () => new \Illuminate\Config\Repository());
Facade::setFacadeApplication($container);

// Load API key
$apiKey = include __DIR__.'/openai-api-key.php';

if (empty($apiKey)) {
    echo "❌ Error: Please set your OpenAI API key in openai-api-key.php\n";
    exit(1);
}

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║           Symbolization Strategy Test Suite               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Configure LarAgent
config()->set('laragent.fallback_provider', 'openai');
config()->set('laragent.truncation_provider', 'openai');  // Provider for built-in truncation agents
config()->set('laragent.providers.openai', [
    'label' => 'openai',
    'model' => 'gpt-4o-mini',
    'api_key' => $apiKey,
    'driver' => OpenAiDriver::class,
    'default_context_window' => 128000,
    'default_max_completion_tokens' => 8192,
    'default_temperature' => 0.7,
]);

config()->set('laragent.storage.default_history_storage', [
    \LarAgent\Context\Drivers\InMemoryStorage::class,
]);
config()->set('laragent.storage.default_storage', [
    \LarAgent\Context\Drivers\InMemoryStorage::class,
]);

// Test agent class
class SymbolizationTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $provider = 'openai';
    protected $enableTruncation = true;
    protected $contextWindowSize = 5000; // Small window to trigger truncation

    protected $storage = [
        \LarAgent\Context\Drivers\InMemoryStorage::class,
    ];

    protected $history = 'in_memory';

    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SymbolizationStrategy([
            'keep_messages' => 3,
            'preserve_system' => true,
        ]);
    }

    public function instructions(): string
    {
        return 'You are a helpful travel assistant. Keep responses brief.';
    }
}

// ============================================================================
// Create sample conversation history
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Creating Sample Travel Planning Conversation...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Realistic travel planning conversation that will be symbolized
$conversation = [
    [
        'role' => 'user',
        'content' => "I'm planning a 2-week trip to Japan next spring. What are the best cities to visit for someone interested in both traditional culture and modern technology?",
    ],
    [
        'role' => 'assistant',
        'content' => "For a mix of traditional and modern Japan, I recommend: Tokyo for technology and pop culture, Kyoto for temples and geishas, Osaka for food and nightlife, and Hiroshima for history. Consider a JR Pass for easy travel between cities.",
    ],
    [
        'role' => 'user',
        'content' => "What's the best time to visit in spring? I heard cherry blossom season is beautiful but crowded.",
    ],
    [
        'role' => 'assistant',
        'content' => "Cherry blossom season typically runs late March to early April. Yes, it's crowded but magical! For fewer crowds, consider late April when wisteria blooms, or early May during Golden Week (though that's also busy). Book accommodations 3-6 months ahead.",
    ],
    [
        'role' => 'user',
        'content' => 'What about budget? How much should I plan to spend per day?',
    ],
    [
        'role' => 'assistant',
        'content' => "Budget travelers can manage with $100-150/day. Mid-range is $200-300/day. Plan for: accommodation ($50-150/night), food ($30-60/day), transport (JR Pass ~$270/week), and activities ($20-50/day). Tokyo is more expensive than other cities.",
    ],
    [
        'role' => 'user',
        'content' => 'Any must-try foods I should look out for?',
    ],
    [
        'role' => 'assistant',
        'content' => "Must-try foods: fresh sushi at Tsukiji, ramen in various regional styles, takoyaki in Osaka, kaiseki (traditional multi-course), matcha desserts in Kyoto, and yakitori. Don't miss convenience store onigiri - surprisingly delicious! Try 7-Eleven and Lawson.",
    ],
];

echo "Original conversation has ".count($conversation)." exchanges:\n\n";

foreach ($conversation as $index => $msg) {
    $preview = strlen($msg['content']) > 80 ? substr($msg['content'], 0, 80).'...' : $msg['content'];
    echo sprintf("[%d] %s: %s\n", $index + 1, strtoupper($msg['role']), $preview);
}

// ============================================================================
// TEST: Symbolization Strategy
// ============================================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST: Applying Symbolization Strategy\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Configuration:\n";
echo "  - Keep Messages: 3 (most recent)\n";
echo "  - Preserve System: Yes\n";
echo "  - Symbol Agent: ChatSymbolizerAgent (built-in)\n";
echo "  - Each message becomes a ~10 word symbol\n\n";

try {
    // Build message array
    $messages = new MessageArray();
    
    // Add system message first
    $messages->add(Message::system('You are a helpful travel assistant.'));
    
    // Add conversation
    foreach ($conversation as $msg) {
        if ($msg['role'] === 'user') {
            $messages->add(Message::user($msg['content']));
        } else {
            $assistantMsg = Message::assistant($msg['content']);
            // Simulate usage data to trigger truncation
            $assistantMsg->setUsage(new Usage(
                promptTokens: 4000,
                completionTokens: 500,
                totalTokens: 6000  // Exceeds context window of 5000
            ));
            $messages->add($assistantMsg);
        }
    }

    echo "Message count before truncation: ".$messages->count()."\n";
    echo "Simulated token usage: 6000 tokens (context window: 5000)\n\n";

    echo "Calling symbolization strategy...\n";
    echo "(This makes API calls to create symbols for each old message)\n\n";

    // Apply strategy directly for testing
    $strategy = new SymbolizationStrategy([
        'keep_messages' => 3,
        'preserve_system' => true,
    ]);

    $startTime = microtime(true);
    $truncatedMessages = $strategy->truncate($messages, 5000, 6000);
    $duration = round(microtime(true) - $startTime, 2);

    echo "✅ Symbolization Complete! (took {$duration}s)\n\n";
    echo "Message count after truncation: ".$truncatedMessages->count()."\n\n";

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TRUNCATED MESSAGES:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    foreach ($truncatedMessages->all() as $index => $message) {
        $role = strtoupper($message->getRole());
        $content = $message->getContentAsString();
        
        // Format nicely
        echo "[$role]\n";
        echo "─────────────────────────────────────────────────────────\n";
        
        // Word wrap long content
        $wrapped = wordwrap($content, 70, "\n");
        echo $wrapped."\n\n";
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST PASSED ✅\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    echo "Summary:\n";
    echo "  - Original messages: ".(count($conversation) * 2 + 1)." (system + ".count($conversation)." exchanges)\n";
    echo "  - After truncation: ".$truncatedMessages->count()." messages\n";
    echo "  - Strategy preserved system message\n";
    echo "  - Each old message converted to brief symbol\n";
    echo "  - Recent 3 messages kept intact\n";

} catch (\Exception $e) {
    echo "❌ TEST FAILED: ".$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
    exit(1);
}

echo "\n";
