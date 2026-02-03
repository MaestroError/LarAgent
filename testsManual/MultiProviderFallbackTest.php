<?php

/**
 * Multi-Provider Fallback Manual Test
 *
 * This test verifies the array-based multi-provider fallback feature
 * introduced in PR #139. Tests use real API providers to ensure the
 * functionality works in production scenarios.
 *
 * Features tested:
 * - Array-based provider configuration with fallback sequence
 * - Per-provider config overrides
 * - Backward compatibility with single string provider
 * - Backward compatibility with deprecated fallback_provider config
 * - default_providers config option
 * - Provider reset on subsequent calls
 * - Agent-defined property preservation
 * - Streaming with fallback
 */

use LarAgent\Agent;
use LarAgent\Drivers\OpenAi\GeminiDriver;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Tests\TestCase;
use LarAgent\Tool;

uses(TestCase::class);

// Load API keys
$openaiKey = null;
$geminiKey = null;

beforeEach(function () use (&$openaiKey, &$geminiKey) {
    $openaiKeyPath = __DIR__.'/openai-api-key.php';
    $geminiKeyPath = __DIR__.'/gemini-api-key.php';

    if (file_exists($openaiKeyPath)) {
        $openaiKey = include $openaiKeyPath;
    }
    if (file_exists($geminiKeyPath)) {
        $geminiKey = include $geminiKeyPath;
    }

    // Configure providers
    config()->set('laragent.fallback_provider', null);
    config()->set('laragent.default_providers', null);

    config()->set('laragent.providers.openai', [
        'label' => 'openai',
        'api_key' => $openaiKey,
        'driver' => OpenAiDriver::class,
        'default_truncation_threshold' => 50000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
        'model' => 'gpt-4.1-nano',
    ]);

    config()->set('laragent.providers.gemini', [
        'label' => 'gemini',
        'api_key' => $geminiKey,
        'driver' => GeminiDriver::class,
        'default_truncation_threshold' => 1000000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
        'model' => 'gemini-2.0-flash',
    ]);

    // Provider with invalid API key to simulate failure
    config()->set('laragent.providers.invalid_openai', [
        'label' => 'invalid_openai',
        'api_key' => 'invalid-api-key-12345',
        'driver' => OpenAiDriver::class,
        'default_truncation_threshold' => 50000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
        'model' => 'gpt-4.1-nano',
    ]);

    // Provider with invalid API key (Gemini)
    config()->set('laragent.providers.invalid_gemini', [
        'label' => 'invalid_gemini',
        'api_key' => 'invalid-gemini-key-12345',
        'driver' => GeminiDriver::class,
        'default_truncation_threshold' => 1000000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
        'model' => 'gemini-2.0-flash',
    ]);
});

// ============================================================================
// Test Agent Definitions
// ============================================================================

/**
 * Agent using single string provider (backward compatibility)
 */
class SingleProviderAgent extends Agent
{
    protected $provider = 'openai';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Respond in one short sentence.';
    }
}

/**
 * Agent using array-based multi-provider fallback
 * Primary provider has invalid key, fallback to Gemini
 */
class MultiProviderFallbackAgent extends Agent
{
    protected $provider = [
        'invalid_openai',  // Will fail - invalid key
        'gemini',          // Should succeed
    ];

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Respond in one short sentence.';
    }
}

/**
 * Agent with per-provider config overrides
 */
class ProviderOverrideAgent extends Agent
{
    protected $provider = [
        'invalid_openai',
        'gemini' => ['model' => 'gemini-2.5-flash'],  // Override model
    ];

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Respond in one short sentence.';
    }
}

/**
 * Agent with agent-defined model that should be preserved
 */
class AgentDefinedModelAgent extends Agent
{
    protected $model = 'gpt-4.1-nano';

    protected $provider = 'openai';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Respond in one short sentence.';
    }
}

/**
 * Agent with all invalid providers (should fail)
 */
class AllInvalidProvidersAgent extends Agent
{
    protected $provider = [
        'invalid_openai',
        'invalid_gemini',
    ];

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant.';
    }
}

/**
 * Agent that uses default_providers config
 */
class UseDefaultProvidersAgent extends Agent
{
    protected $provider = null;  // Will use default_providers config

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Respond in one short sentence.';
    }
}

/**
 * Agent with tools for testing fallback with function calling
 */
class MultiProviderToolAgent extends Agent
{
    protected $provider = [
        'invalid_openai',
        'gemini',
    ];

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant with weather capabilities.';
    }

    public function registerTools(): array
    {
        return [
            Tool::create('get_weather', 'Get the current weather for a location')
                ->addProperty('location', 'string', 'The city name')
                ->setRequired('location')
                ->setCallback(function ($location) {
                    return "The weather in {$location} is sunny with 25°C.";
                }),
        ];
    }
}

// ============================================================================
// Test Cases
// ============================================================================

test('backward compatibility: single string provider works', function () use (&$openaiKey) {
    if (! $openaiKey) {
        $this->markTestSkipped('OpenAI API key not configured');
    }

    $agent = new SingleProviderAgent('test_single_provider');

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect(strlen($response))->toBeGreaterThan(0);
    expect($agent->getProviderSequence())->toEqual(['openai']);
    expect($agent->getActiveProviderName())->toBe('openai');
});

test('multi-provider fallback: falls back when primary fails', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new MultiProviderFallbackAgent('test_fallback');

    // Should start with invalid_openai
    expect($agent->getActiveProviderName())->toBe('invalid_openai');
    expect($agent->getProviderSequence())->toEqual(['invalid_openai', 'gemini']);

    $response = $agent->respond('Say hello');

    // Should have fallen back to gemini
    expect($response)->toBeString();
    expect(strlen($response))->toBeGreaterThan(0);
    expect($agent->getActiveProviderName())->toBe('gemini');
    expect($agent->getProviderName())->toBe('gemini');
});

test('multi-provider fallback: per-provider config overrides work', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new ProviderOverrideAgent('test_override');

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect($agent->getActiveProviderName())->toBe('gemini');
    // The model should be the override value
    expect($agent->model())->toBe('gemini-2.5-flash');
});

test('multi-provider fallback: agent-defined model preserved when provider does not override', function () use (&$openaiKey) {
    if (! $openaiKey) {
        $this->markTestSkipped('OpenAI API key not configured');
    }

    $agent = new AgentDefinedModelAgent('test_agent_model');

    // Agent-defined model should be preserved
    expect($agent->model())->toBe('gpt-4.1-nano');

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    // Model should still be agent-defined
    expect($agent->model())->toBe('gpt-4.1-nano');
});

test('multi-provider fallback: throws exception when all providers fail', function () {
    $agent = new AllInvalidProvidersAgent('test_all_fail');

    expect(fn () => $agent->respond('Say hello'))->toThrow(Exception::class);
});

test('default_providers config: uses fallback from config', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    // Configure default_providers with invalid first, valid second
    config()->set('laragent.default_providers', [
        'invalid_openai',
        'gemini',
    ]);

    $agent = new UseDefaultProvidersAgent('test_default_providers');

    expect($agent->getProviderSequence())->toEqual(['invalid_openai', 'gemini']);

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect($agent->getActiveProviderName())->toBe('gemini');
});

test('default_providers config: supports per-provider overrides', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    config()->set('laragent.default_providers', [
        'invalid_openai',
        'gemini' => ['model' => 'gemini-2.5-flash'],
    ]);

    $agent = new UseDefaultProvidersAgent('test_default_override');

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect($agent->model())->toBe('gemini-2.5-flash');
});

test('deprecated fallback_provider config: still works for backward compatibility', function () use (&$openaiKey, &$geminiKey) {
    if (! $openaiKey || ! $geminiKey) {
        $this->markTestSkipped('API keys not configured');
    }

    // Use deprecated fallback_provider
    config()->set('laragent.fallback_provider', 'gemini');

    // Create agent with invalid primary provider
    $agent = new class('test_deprecated_fallback') extends Agent
    {
        protected $provider = 'invalid_openai';

        protected $history = 'in_memory';

        public function instructions()
        {
            return 'You are a helpful assistant. Respond in one short sentence.';
        }
    };

    // Provider sequence should include both
    expect($agent->getProviderSequence())->toEqual(['invalid_openai', 'gemini']);

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect($agent->getActiveProviderName())->toBe('gemini');
});

test('provider reset: resets to first provider on subsequent calls', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new MultiProviderFallbackAgent('test_reset');

    // Initial state - should be first provider
    expect($agent->getActiveProviderName())->toBe('invalid_openai');

    // First call - should fall back to gemini
    $response1 = $agent->respond('Say hello');
    expect($response1)->toBeString();
    // After successful fallback, active provider should be gemini (index 1)
    expect($agent->getActiveProviderName())->toBe('gemini');

    // Second call - should reset to first provider, fail, then fall back to gemini again
    $response2 = $agent->respond('Say goodbye');
    expect($response2)->toBeString();
    // After second fallback, active provider should be gemini again
    expect($agent->getActiveProviderName())->toBe('gemini');

    // Third call - verify consistency
    $response3 = $agent->respond('Say thanks');
    expect($response3)->toBeString();
    expect($agent->getActiveProviderName())->toBe('gemini');
});

test('streaming: multi-provider fallback works with streaming', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new MultiProviderFallbackAgent('test_streaming');

    $stream = $agent->respondStreamed('Count from 1 to 3');

    $chunks = [];
    foreach ($stream as $message) {
        if ($message instanceof StreamedAssistantMessage) {
            $chunks[] = $message->getContentAsString();
        }
    }

    expect($chunks)->not->toBeEmpty();
    // Should have fallen back to gemini
    expect($agent->getActiveProviderName())->toBe('gemini');
});

test('tools: multi-provider fallback works with tool calls', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new MultiProviderToolAgent('test_tools');

    $response = $agent->respond('What is the weather in Paris?');

    expect($response)->toBeString();
    // Should mention weather or Paris
    expect(str_contains(strtolower($response), 'paris') || str_contains(strtolower($response), 'weather') || str_contains(strtolower($response), 'sunny'))->toBeTrue();
    expect($agent->getActiveProviderName())->toBe('gemini');
});

test('debugging: getProviderSequence returns correct sequence', function () {
    $agent = new MultiProviderFallbackAgent('test_sequence');

    $sequence = $agent->getProviderSequence();

    expect($sequence)->toBeArray();
    expect($sequence)->toEqual(['invalid_openai', 'gemini']);
});

test('debugging: getActiveProviderName returns correct provider', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    $agent = new MultiProviderFallbackAgent('test_active_provider');

    // Before respond, should be first provider
    expect($agent->getActiveProviderName())->toBe('invalid_openai');

    $agent->respond('Hello');

    // After respond with fallback, should be second provider
    expect($agent->getActiveProviderName())->toBe('gemini');
});

// ============================================================================
// Real Cross-Provider Fallback Tests (OpenAI -> Gemini)
// ============================================================================

test('real fallback: OpenAI primary to Gemini fallback with valid keys', function () use (&$openaiKey, &$geminiKey) {
    if (! $openaiKey || ! $geminiKey) {
        $this->markTestSkipped('Both API keys required');
    }

    // Create agent that uses OpenAI as primary, Gemini as fallback
    // Both should work, so we'll verify primary is used when available
    $agent = new class('test_real_fallback') extends Agent
    {
        protected $provider = [
            'openai',
            'gemini',
        ];

        protected $history = 'in_memory';

        public function instructions()
        {
            return 'You are a helpful assistant. Respond with exactly one word.';
        }
    };

    expect($agent->getProviderSequence())->toEqual(['openai', 'gemini']);
    expect($agent->getActiveProviderName())->toBe('openai');

    $response = $agent->respond('Say "hello"');

    expect($response)->toBeString();
    // Since OpenAI is valid, it should succeed with OpenAI
    expect($agent->getActiveProviderName())->toBe('openai');
});

test('real fallback: works across different driver types', function () use (&$geminiKey) {
    if (! $geminiKey) {
        $this->markTestSkipped('Gemini API key not configured');
    }

    // Test fallback from OpenAI driver to Gemini driver
    $agent = new class('test_cross_driver') extends Agent
    {
        protected $provider = [
            'invalid_openai',  // OpenAI driver, invalid key
            'gemini',          // Gemini driver, valid key
        ];

        protected $history = 'in_memory';

        public function instructions()
        {
            return 'You are a helpful assistant.';
        }
    };

    $response = $agent->respond('Say hello');

    expect($response)->toBeString();
    expect($agent->getActiveProviderName())->toBe('gemini');
    expect($agent->getProviderName())->toBe('gemini');
});
