<?php

/**
 * User Confirmation Flow Test
 *
 * This manual test demonstrates the phantom tool user confirmation pattern.
 * When the LLM decides to call a phantom tool (like processing a payment),
 * the tool call is returned to the application instead of being executed automatically.
 * The user is then prompted for confirmation before the tool is executed.
 *
 * Run: php ./testsManual/UserConfirmationFlowTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Agent;
use LarAgent\Message;
use LarAgent\PhantomTool;

// Config helper for standalone execution
function config(string $key): mixed
{
    $yourApiKey = include __DIR__.'/openai-api-key.php';

    $config = [
        'laragent.default_driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => \LarAgent\History\InMemoryChatHistory::class,
        'laragent.default_usage_storage' => null,
        'laragent.default_storage' => null,
        'laragent.default_history_storage' => null,
        'laragent.track_usage' => false,
        'laragent.enable_truncation' => false,
        'laragent.fallback_provider' => null,
        'laragent.providers.default' => [
            'label' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $yourApiKey,
            'default_context_window' => 128000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 0.7,
            'track_usage' => false,
            'enable_truncation' => false,
        ],
        'laragent.providers.default.track_usage' => false,
        'laragent.providers.default.enable_truncation' => false,
    ];

    return $config[$key] ?? null;
}

/**
 * Payment Agent with Phantom Tool
 *
 * This agent has a phantom tool for processing payments.
 * Instead of executing automatically, it returns the tool call
 * so the application can request user confirmation.
 */
class PaymentAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function instructions()
    {
        return <<<'INSTRUCTIONS'
You are a payment assistant that helps users process payments.
When a user wants to make a payment, use the process_payment tool.
After successfully processing a payment, inform the user with the transaction details.
If a payment fails or is cancelled, acknowledge it politely.
Be helpful and concise in your responses.
INSTRUCTIONS;
    }

    public function registerTools()
    {
        return [
            PhantomTool::create('process_payment', 'Process a payment transaction')
                ->addProperty('amount', 'number', 'Payment amount in cents (e.g., 5000 for $50.00)')
                ->addProperty('currency', 'string', 'Currency code (e.g., USD, EUR)')
                ->addProperty('description', 'string', 'Payment description')
                ->setRequired('amount')
                ->setRequired('currency')
                ->setRequired('description'),
        ];
    }
}

/**
 * Simulated Payment Service
 */
class PaymentService
{
    public static function charge(array $args): array
    {
        // Simulate payment processing
        $transactionId = 'txn_'.bin2hex(random_bytes(8));

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'amount' => $args['amount'],
            'currency' => $args['currency'],
            'description' => $args['description'],
            'processed_at' => date('Y-m-d H:i:s'),
        ];
    }
}

/**
 * Helper function to get user input from terminal
 */
function prompt(string $message): string
{
    echo $message;

    return trim(fgets(STDIN));
}

/**
 * Format amount from cents to dollars
 */
function formatAmount(int $cents, string $currency): string
{
    return $currency.' '.number_format($cents / 100, 2);
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           PHANTOM TOOL USER CONFIRMATION FLOW TEST           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Step 1: User makes a request
$userMessage = 'I want to pay $75.50 for my monthly subscription';
echo "📝 User Message: \"{$userMessage}\"\n";
echo str_repeat('-', 60)."\n\n";

// Step 2: Send to agent
echo "🤖 Sending to PaymentAgent...\n\n";

$agent = PaymentAgent::for('user-confirmation-test');
// Force the tool to be called so we can demonstrate the confirmation flow
$response = $agent->forceTool('process_payment')->respond($userMessage);

// Step 3: Check if phantom tool was called
if (is_array($response) && isset($response['tool_calls'])) {
    $toolCall = $response['tool_calls'][0];
    $toolName = $toolCall['function']['name'];
    $toolCallId = $toolCall['id'];
    $args = json_decode($toolCall['function']['arguments'], true);

    echo "🔧 Phantom Tool Called: {$toolName}\n";
    echo "   Tool Call ID: {$toolCallId}\n";
    echo "   Arguments:\n";
    foreach ($args as $key => $value) {
        echo "     - {$key}: {$value}\n";
    }
    echo "\n";

    // Step 4: Request user confirmation
    echo str_repeat('=', 60)."\n";
    echo "⚠️  CONFIRMATION REQUIRED\n";
    echo str_repeat('=', 60)."\n\n";

    $formattedAmount = formatAmount($args['amount'], $args['currency']);
    echo "You are about to process a payment:\n";
    echo "  💰 Amount: {$formattedAmount}\n";
    echo "  📋 Description: {$args['description']}\n\n";

    $confirmation = prompt('Do you want to proceed? (yes/no): ');
    echo "\n";

    if (strtolower($confirmation) === 'yes' || strtolower($confirmation) === 'y') {
        // Step 5: Execute the tool externally
        echo "✅ User confirmed. Processing payment...\n\n";

        $result = PaymentService::charge($args);

        echo "💳 Payment Result:\n";
        echo "   Transaction ID: {$result['transaction_id']}\n";
        echo '   Status: '.($result['success'] ? 'Success ✓' : 'Failed ✗')."\n";
        echo "   Processed At: {$result['processed_at']}\n\n";

        // Step 6: Add tool result and continue conversation
        $agent->addMessage(Message::toolResult(
            json_encode($result),
            $toolCallId,
            $toolName
        ));

        echo "📤 Sending result back to agent...\n\n";

        // Reset tool choice to auto for the final response
        $finalResponse = $agent->toolAuto()->respond();

        echo str_repeat('-', 60)."\n";
        echo "🤖 Agent Response:\n\n";
        $responseText = is_array($finalResponse) ? json_encode($finalResponse, JSON_PRETTY_PRINT) : (string) $finalResponse;
        echo '   '.str_replace("\n", "\n   ", $responseText)."\n";

    } else {
        // Step 5b: User declined
        echo "❌ User declined. Payment cancelled.\n\n";

        // Inform the agent about the cancellation
        $agent->addMessage(Message::toolResult(
            json_encode(['success' => false, 'error' => 'User cancelled the payment']),
            $toolCallId,
            $toolName
        ));

        // Use toolNone to prevent re-calling the tool after cancellation
        $finalResponse = $agent->toolNone()->respond();

        echo str_repeat('-', 60)."\n";
        echo "🤖 Agent Response:\n\n";
        $responseText = is_array($finalResponse) ? json_encode($finalResponse, JSON_PRETTY_PRINT) : (string) $finalResponse;
        echo '   '.str_replace("\n", "\n   ", $responseText)."\n";
    }

} else {
    // No tool call - just a regular response
    echo "🤖 Agent Response (no tool call):\n\n";
    echo '   '.str_replace("\n", "\n   ", (string) $response)."\n";
}

echo "\n";
echo str_repeat('=', 60)."\n";
echo "✨ Test completed!\n";
echo str_repeat('=', 60)."\n\n";
