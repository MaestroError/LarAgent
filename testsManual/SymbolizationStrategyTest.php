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

// Custom strategy to debug and track batch calls
class DebugSymbolizationStrategy extends SymbolizationStrategy
{
    public array $batchCalls = [];  // Track batch sizes for verification

    protected function symbolizeBatch(array $messages, string $agentClass, int $batchIndex): array
    {
        $batchSize = count($messages);
        $this->batchCalls[] = $batchSize;

        echo "\n  [BATCH {$batchIndex}] Processing {$batchSize} messages...\n";

        $result = parent::symbolizeBatch($messages, $agentClass, $batchIndex);

        echo "  [BATCH {$batchIndex}] ✅ Generated ".count($result)." symbols\n";

        return $result;
    }
}

// Bootstrap minimal Laravel environment
$container = new Container;
Container::setInstance($container);
$container->singleton('events', fn () => new Dispatcher($container));
$container->singleton('config', fn () => new \Illuminate\Config\Repository);
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

// Realistic travel planning conversation that will be symbolized (25 messages total including system)
// With keep_messages=3, we need to symbolize 22 messages -> batches of 10, 10, 2
$conversation = [
    // Exchange 1-2
    [
        'role' => 'user',
        'content' => "I'm planning a 2-week trip to Japan next spring. What are the best cities to visit for someone interested in both traditional culture and modern technology?",
    ],
    [
        'role' => 'assistant',
        'content' => 'For a mix of traditional and modern Japan, I recommend: Tokyo for technology and pop culture, Kyoto for temples and geishas, Osaka for food and nightlife, and Hiroshima for history. Consider a JR Pass for easy travel between cities.',
    ],
    // Exchange 3-4
    [
        'role' => 'user',
        'content' => "What's the best time to visit in spring? I heard cherry blossom season is beautiful but crowded.",
    ],
    [
        'role' => 'assistant',
        'content' => "Cherry blossom season typically runs late March to early April. Yes, it's crowded but magical! For fewer crowds, consider late April when wisteria blooms, or early May during Golden Week (though that's also busy). Book accommodations 3-6 months ahead.",
    ],
    // Exchange 5-6
    [
        'role' => 'user',
        'content' => 'What about budget? How much should I plan to spend per day?',
    ],
    [
        'role' => 'assistant',
        'content' => 'Budget travelers can manage with $100-150/day. Mid-range is $200-300/day. Plan for: accommodation ($50-150/night), food ($30-60/day), transport (JR Pass ~$270/week), and activities ($20-50/day). Tokyo is more expensive than other cities.',
    ],
    // Exchange 7-8
    [
        'role' => 'user',
        'content' => 'Any must-try foods I should look out for?',
    ],
    [
        'role' => 'assistant',
        'content' => "Must-try foods: fresh sushi at Tsukiji, ramen in various regional styles, takoyaki in Osaka, kaiseki (traditional multi-course), matcha desserts in Kyoto, and yakitori. Don't miss convenience store onigiri - surprisingly delicious! Try 7-Eleven and Lawson.",
    ],
    // Exchange 9-10
    [
        'role' => 'user',
        'content' => 'How do I get around between cities? Is the train system easy to navigate?',
    ],
    [
        'role' => 'assistant',
        'content' => "Japan's train system is excellent! Get a 14-day JR Pass before arriving (~$450). The Shinkansen (bullet train) connects major cities quickly - Tokyo to Kyoto is just 2 hours. Google Maps works great for navigation, and station signs are in English.",
    ],
    // Exchange 11-12
    [
        'role' => 'user',
        'content' => 'What about language? I dont speak Japanese. Will that be a problem?',
    ],
    [
        'role' => 'assistant',
        'content' => "English signage is common in tourist areas. Learn basic phrases: 'Sumimasen' (excuse me), 'Arigatou' (thanks), 'Eigo?' (English?). Download Google Translate with Japanese offline. Younger Japanese often speak some English. Point-and-show menus help at restaurants.",
    ],
    // Exchange 13-14
    [
        'role' => 'user',
        'content' => 'Are there any cultural etiquette tips I should know about?',
    ],
    [
        'role' => 'assistant',
        'content' => "Key etiquette: Remove shoes indoors, bow slightly when greeting, don't tip (it's considered rude), be quiet on trains, don't eat while walking. At temples: wash hands at purification fountains, don't photograph where prohibited. Cash is still king in many places.",
    ],
    // Exchange 15-16
    [
        'role' => 'user',
        'content' => 'Should I book accommodations in advance or can I be flexible?',
    ],
    [
        'role' => 'assistant',
        'content' => 'Book cherry blossom season 3-6 months ahead - hotels fill up fast! Mix it up: try a traditional ryokan (Japanese inn) in Kyoto, business hotels in Tokyo, and maybe a capsule hotel for the experience. Booking.com and Agoda work well. Consider Airbnb for longer stays.',
    ],
    // Exchange 17-18
    [
        'role' => 'user',
        'content' => 'What are some off-the-beaten-path experiences you would recommend?',
    ],
    [
        'role' => 'assistant',
        'content' => "Hidden gems: Naoshima art island, Koyasan temple stay, Kanazawa's Kenrokuen garden, Nara's deer park, Arashiyama bamboo grove early morning (before crowds). In Tokyo, explore Shimokitazawa for vintage shops and Yanaka for old-town feel. Night walks in Gion might reveal geishas.",
    ],
    // Exchange 19-20
    [
        'role' => 'user',
        'content' => 'I love photography. What are the best spots for taking pictures?',
    ],
    [
        'role' => 'assistant',
        'content' => "Photo spots: Fushimi Inari torii gates at sunrise, Shibuya crossing from above (Starbucks), Mt. Fuji from Lake Kawaguchi, Kinkaku-ji golden pavilion, Tokyo Tower at night. Cherry blossoms along Philosopher's Path in Kyoto. The teamLab digital art museums are incredible for photos!",
    ],
    // Exchange 21-22
    [
        'role' => 'user',
        'content' => 'Any day trips you would suggest from Tokyo or Kyoto?',
    ],
    [
        'role' => 'assistant',
        'content' => "From Tokyo: Nikko for ornate temples (2hrs), Kamakura for giant Buddha and beaches (1hr), Hakone for hot springs and Fuji views (1.5hrs). From Kyoto: Nara for temples and deer (45min), Himeji Castle - Japan's finest (1hr), Osaka for food adventures (30min). All covered by JR Pass!",
    ],
    // Exchange 23-24 (these will be kept along with the last user message)
    [
        'role' => 'user',
        'content' => 'What about mobile connectivity? Should I get a SIM card or pocket WiFi?',
    ],
    [
        'role' => 'assistant',
        'content' => "Pocket WiFi is popular - rent at airport, unlimited data, connect multiple devices. Or get a tourist SIM (Sakura Mobile, Mobal). Many prefer eSIM now (Ubigi, Airalo) - no physical swap needed. Free WiFi exists at stations and convenience stores but isn't reliable. Budget ~$50-80 for 2 weeks.",
    ],
];

echo 'Original conversation has '.count($conversation).' exchanges ('.count($conversation).' messages + 1 system = '.(count($conversation) + 1)." total):\n\n";

foreach ($conversation as $index => $msg) {
    $preview = strlen($msg['content']) > 60 ? substr($msg['content'], 0, 60).'...' : $msg['content'];
    echo sprintf("[%02d] %s: %s\n", $index + 1, strtoupper(substr($msg['role'], 0, 4)), $preview);
}

// ============================================================================
// TEST: Symbolization Strategy
// ============================================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST: Applying Symbolization Strategy\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Configuration:\n";
echo '  - Total Messages: '.(count($conversation) + 1).' (1 system + '.count($conversation)." conversation)\n";
echo "  - Keep Messages: 3 (most recent conversation messages)\n";
echo "  - System preserved separately\n";
echo '  - Messages to Symbolize: '.(count($conversation) - 3)." messages (conversation - kept)\n";
echo "  - Expected Batches: 10 + 10 + 1 = 3 API calls\n";
echo "  - Symbol Agent: ChatSymbolizerAgent (built-in)\n\n";

try {
    // Build message array
    $messages = new MessageArray;

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

    echo 'Message count before truncation: '.$messages->count()."\n";
    echo "Simulated token usage: 6000 tokens (context window: 5000)\n\n";

    echo "Calling symbolization strategy...\n";
    echo "(This makes API calls to create symbols for each old message)\n\n";

    // Apply strategy directly for testing
    $strategy = new DebugSymbolizationStrategy([
        'keep_messages' => 3,
        'preserve_system' => true,
    ]);

    $startTime = microtime(true);
    $truncatedMessages = $strategy->truncate($messages, 5000, 6000);
    $duration = round(microtime(true) - $startTime, 2);

    echo "✅ Symbolization Complete! (took {$duration}s)\n\n";

    // Verify batch sizes
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "BATCH VERIFICATION:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    echo "Expected batches: [10, 10, 1]\n";
    echo 'Actual batches:   ['.implode(', ', $strategy->batchCalls)."]\n\n";

    $expectedBatches = [10, 10, 1];
    if ($strategy->batchCalls === $expectedBatches) {
        echo "✅ Batch sizes match expected pattern!\n\n";
    } else {
        echo "❌ Batch sizes do NOT match expected pattern!\n\n";
    }

    echo 'Message count after truncation: '.$truncatedMessages->count()."\n\n";

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
    echo '  - Original messages: '.(count($conversation) + 1).' (system + '.count($conversation)." conversation)\n";
    echo '  - Messages symbolized: '.(count($conversation) - 3)."\n";
    echo '  - API calls made: '.count($strategy->batchCalls).' (batches: '.implode(', ', $strategy->batchCalls).")\n";
    echo '  - After truncation: '.$truncatedMessages->count()." messages\n";
    echo "  - Strategy preserved system message separately\n";
    echo "  - Recent 3 conversation messages kept intact\n";

} catch (\Exception $e) {
    echo '❌ TEST FAILED: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
    exit(1);
}

echo "\n";
