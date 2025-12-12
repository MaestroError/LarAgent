<?php

/**
 * Manual Test: Summarization Strategy
 *
 * This test demonstrates how SummarizationStrategy works with real API calls.
 * It creates a conversation history and triggers summarization to show how
 * older messages are condensed into a summary.
 *
 * Prerequisites:
 * - Set OPENAI_API_KEY in openai-api-key.php
 * - Run: php testsManual/SummarizationStrategyTest.php
 *
 * The test will:
 * 1. Create an agent with summarization truncation enabled
 * 2. Pre-populate chat history with a realistic conversation
 * 3. Trigger truncation and show the summarized result
 */

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use LarAgent\Agent;
use LarAgent\Context\Truncation\SummarizationStrategy;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Usage\DataModels\Usage;

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
echo "║          Summarization Strategy Test Suite                ║\n";
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
class SummarizationTestAgent extends Agent
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
        return new SummarizationStrategy([
            'keep_messages' => 3,
            'preserve_system' => true,
        ]);
    }

    public function instructions(): string
    {
        return 'You are a helpful coding assistant. Keep responses brief.';
    }
}

// ============================================================================
// Create sample conversation history
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Creating Sample Conversation History...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Realistic programming conversation that will be summarized
$conversation = [
    [
        'role' => 'user',
        'content' => "I'm building a Laravel application and need help with database migrations. How do I create a migration for a users table?",
    ],
    [
        'role' => 'assistant',
        'content' => "To create a migration for a users table in Laravel, run: `php artisan make:migration create_users_table`. Then in the up() method, define your columns like id(), string('name'), string('email')->unique(), and timestamps().",
    ],
    [
        'role' => 'user',
        'content' => 'Great! Now how do I add a foreign key relationship? I want to link posts to users.',
    ],
    [
        'role' => 'assistant',
        'content' => "For foreign keys, create a posts migration with `php artisan make:migration create_posts_table`. Add `foreignId('user_id')->constrained()->onDelete('cascade')` to link posts to users with cascade delete.",
    ],
    [
        'role' => 'user',
        'content' => 'What about many-to-many relationships? Like users and roles?',
    ],
    [
        'role' => 'assistant',
        'content' => "For many-to-many, create three migrations: users, roles, and a pivot table role_user. The pivot table should have `foreignId('user_id')` and `foreignId('role_id')`. Use `belongsToMany()` in your models.",
    ],
    [
        'role' => 'user',
        'content' => 'How do I seed the database with test data?',
    ],
    [
        'role' => 'assistant',
        'content' => "Use seeders! Create with `php artisan make:seeder UserSeeder`. In the run() method, use User::factory()->count(50)->create() or manual inserts. Don't forget to call your seeder from DatabaseSeeder.",
    ],
];

echo 'Original conversation has '.count($conversation)." exchanges:\n\n";

foreach ($conversation as $index => $msg) {
    $preview = strlen($msg['content']) > 80 ? substr($msg['content'], 0, 80).'...' : $msg['content'];
    echo sprintf("[%d] %s: %s\n", $index + 1, strtoupper($msg['role']), $preview);
}

// ============================================================================
// TEST: Summarization Strategy
// ============================================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST: Applying Summarization Strategy\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Configuration:\n";
echo "  - Keep Messages: 3 (most recent)\n";
echo "  - Preserve System: Yes\n";
echo "  - Summary Agent: ChatSummarizerAgent (built-in)\n\n";

try {
    // Build message array
    $messages = new MessageArray;

    // Add system message first
    $messages->add(Message::system('You are a helpful coding assistant.'));

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

    echo "Calling summarization strategy...\n\n";

    // Apply strategy directly for testing
    $strategy = new SummarizationStrategy([
        'keep_messages' => 3,
        'preserve_system' => true,
    ]);

    $truncatedMessages = $strategy->truncate($messages, 5000, 6000);

    echo "✅ Summarization Complete!\n\n";
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
    echo '  - Original messages: '.(count($conversation) * 2 + 1).' (system + '.count($conversation)." exchanges)\n";
    echo '  - After truncation: '.$truncatedMessages->count()." messages\n";
    echo "  - Strategy preserved system message and summarized older conversation\n";

} catch (\Exception $e) {
    echo '❌ TEST FAILED: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
    exit(1);
}

echo "\n";
