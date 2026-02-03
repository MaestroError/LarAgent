<?php

use LarAgent\Agent;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Tests\LarAgent\Fakes\FailingLlmDriver;
use LarAgent\Tests\LarAgent\Fakes\PreloadedFakeLlmDriver;

/**
 * Agent with array-based multi-provider fallback configuration.
 */
class MultiProviderFallbackTestAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = [
        'fail',
        'fail2',
        'success',
    ];

    public function instructions()
    {
        return 'Multi-provider fallback test agent.';
    }
}

/**
 * Agent with per-provider config overrides.
 */
class ProviderOverrideTestAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = [
        'fail',
        'success' => ['model' => 'custom-override-model'],
    ];

    public function instructions()
    {
        return 'Provider override test agent.';
    }
}

/**
 * Agent with agent-defined model that should be preserved when provider doesn't override.
 */
class AgentDefinedModelTestAgent extends Agent
{
    protected $model = 'agent-defined-model';

    protected $history = 'in_memory';

    protected $provider = [
        'fail',
        'success_no_model',  // This provider won't have a model defined
    ];

    public function instructions()
    {
        return 'Agent defined model test agent.';
    }
}

/**
 * Agent with invalid provider override (string instead of array).
 */
class InvalidOverrideTestAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = [
        'default',
        'success' => 'invalid-string-override',  // Invalid: should be array
    ];

    public function instructions()
    {
        return 'Invalid override test agent.';
    }
}

/**
 * Agent with all failing providers.
 */
class AllFailingProvidersAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = [
        'fail',
        'fail2',
    ];

    public function instructions()
    {
        return 'All failing providers test agent.';
    }
}

/**
 * Agent with single string provider for backward compatibility.
 */
class SingleStringProviderAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = 'success';

    public function instructions()
    {
        return 'Single string provider test agent.';
    }
}

/**
 * Agent that uses default_providers from config.
 * Sets $provider explicitly to 'default' to test that 'default' provider works.
 */
class DefaultProvidersAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    // Using 'default' provider explicitly
    protected $provider = 'default';

    public function instructions()
    {
        return 'Default providers test agent.';
    }
}

/**
 * Agent that doesn't set $provider to test default_providers config.
 */
class UnsetProviderAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    // Provider intentionally not set - will use default_providers config or fall back to 'default'
    protected $provider = null;

    public function instructions()
    {
        return 'Unset provider test agent.';
    }
}

beforeEach(function () {
    config()->set('laragent.providers', [
        'default' => [
            'label' => 'default',
            'driver' => FailingLlmDriver::class,
            'model' => 'gpt-default',
            'api_key' => 'default-key',
            'default_truncation_threshold' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
        'fail' => [
            'label' => 'fail',
            'driver' => FailingLlmDriver::class,
            'model' => 'gpt-fail',
            'api_key' => 'fail',
            'default_truncation_threshold' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
        'fail2' => [
            'label' => 'fail2',
            'driver' => FailingLlmDriver::class,
            'model' => 'gpt-fail2',
            'api_key' => 'fail2',
            'default_truncation_threshold' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
        'success' => [
            'label' => 'success',
            'driver' => PreloadedFakeLlmDriver::class,
            'model' => 'gpt-success',
            'api_key' => 'success',
            'default_truncation_threshold' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
        'success_no_model' => [
            'label' => 'success_no_model',
            'driver' => PreloadedFakeLlmDriver::class,
            // Note: no 'model' defined - agent default should be used
            'api_key' => 'success-no-model',
            'default_truncation_threshold' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
    ]);
    config()->set('laragent.fallback_provider', null);
    config()->set('laragent.default_providers', null);
});

// ==================== Array Provider Tests ====================

it('falls back through multiple providers until success', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
});

it('falls back through multiple providers on streamed failure', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    $stream = $agent->respondStreamed('Hello');

    $chunks = [];
    foreach ($stream as $message) {
        if ($message instanceof StreamedAssistantMessage || $message instanceof \LarAgent\Messages\AssistantMessage) {
            $chunks[] = $message->getContentAsString();
        }
    }

    expect($chunks)->not->toBeEmpty();
    expect(end($chunks))->toBe('fallback response');
});

it('returns provider sequence for debugging', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    $sequence = $agent->getProviderSequence();

    expect($sequence)->toEqual(['fail', 'fail2', 'success']);
});

it('returns active provider name', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    // Initially should be first provider
    expect($agent->getActiveProviderName())->toBe('fail');
});

// ==================== Per-Provider Override Tests ====================

it('respects per-provider config overrides', function () {
    $agent = new ProviderOverrideTestAgent('test_key');

    // Provider sequence should include the one with override
    $sequence = $agent->getProviderSequence();
    expect($sequence)->toEqual(['fail', 'success']);

    // After fallback, should use the override model
    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
    expect($agent->model())->toBe('custom-override-model');
});

// ==================== All Providers Fail Tests ====================

it('throws exception when all providers fail', function () {
    $agent = new AllFailingProvidersAgent('test_key');

    $agent->respond('Hello');
})->throws(Exception::class, 'Simulated failure');

it('throws exception when all providers fail on streamed response', function () {
    $agent = new AllFailingProvidersAgent('test_key');

    $stream = $agent->respondStreamed('Hello');

    // Need to iterate to trigger the exception
    foreach ($stream as $chunk) {
        // This should throw
    }
})->throws(Exception::class, 'Simulated failure');

// ==================== Backward Compatibility Tests ====================

it('maintains backward compatibility with single string provider', function () {
    $agent = new SingleStringProviderAgent('test_key');

    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
    expect($agent->getProviderSequence())->toEqual(['success']);
});

it('maintains backward compatibility with deprecated fallback_provider config', function () {
    // Configure the deprecated fallback_provider
    config()->set('laragent.fallback_provider', 'success');

    $agent = new DefaultProvidersAgent('test_key');

    // Provider sequence should include both default and fallback
    $sequence = $agent->getProviderSequence();
    expect($sequence)->toEqual(['default', 'success']);

    // Should fall back to success provider
    $response = $agent->respond('Hello');
    expect($response)->toBe('fallback response');
});

// ==================== default_providers Config Tests ====================

it('uses default_providers from config when agent provider is not set', function () {
    // Configure default_providers
    config()->set('laragent.default_providers', ['fail', 'success']);

    $agent = new UnsetProviderAgent('test_key');

    // Provider sequence should come from config
    $sequence = $agent->getProviderSequence();
    expect($sequence)->toEqual(['fail', 'success']);

    // Should fall back to success provider
    $response = $agent->respond('Hello');
    expect($response)->toBe('fallback response');
});

it('supports per-provider overrides in default_providers config', function () {
    // Configure default_providers with override
    config()->set('laragent.default_providers', [
        'fail',
        'success' => ['model' => 'config-override-model'],
    ]);

    $agent = new UnsetProviderAgent('test_key');

    // Should fall back to success provider with override
    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
    expect($agent->model())->toBe('config-override-model');
});

it('uses default provider when agent explicitly sets provider to default', function () {
    // Configure default_providers - should NOT be used when agent explicitly sets 'default'
    config()->set('laragent.default_providers', ['fail', 'success']);

    $agent = new DefaultProvidersAgent('test_key');

    // Provider sequence should be just 'default', not the default_providers array
    $sequence = $agent->getProviderSequence();
    expect($sequence)->toEqual(['default']);
});

// ==================== Provider Name Tests ====================

it('returns correct provider name after fallback', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    // Initial provider - getActiveProviderName() returns the provider key
    expect($agent->getActiveProviderName())->toBe('fail');

    // getProviderName() returns the label from provider config
    expect($agent->getProviderName())->toBe('fail');

    // After respond (which triggers fallback)
    $agent->respond('Hello');

    // Now should be the success provider
    expect($agent->getActiveProviderName())->toBe('success');
    expect($agent->getProviderName())->toBe('success');
});

// ==================== Agent Defaults Preservation Tests ====================

it('preserves agent-defined model when provider does not override', function () {
    $agent = new AgentDefinedModelTestAgent('test_key');

    // After fallback to success_no_model provider (which doesn't define model),
    // agent's default model should be preserved
    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
    expect($agent->model())->toBe('agent-defined-model');
});

// ==================== Invalid Configuration Tests ====================

it('throws exception for non-array provider override', function () {
    new InvalidOverrideTestAgent('test_key');
})->throws(\InvalidArgumentException::class, "Provider override for 'success' must be an array");

// ==================== Provider Reset Tests ====================

it('resets to first provider on subsequent respond calls', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    // First call - should fall back through providers until success
    $response1 = $agent->respond('Hello');
    expect($response1)->toBe('fallback response');
    expect($agent->getActiveProviderName())->toBe('success');

    // Second call - should start from first provider again (not stay on 'success')
    // Since first provider fails, it will fallback again
    $response2 = $agent->respond('Hello again');
    expect($response2)->toBe('fallback response');
    // After fallback, we end up on 'success' again
    expect($agent->getActiveProviderName())->toBe('success');
});

it('resets to first provider on subsequent respondStreamed calls', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    // First call
    $stream1 = $agent->respondStreamed('Hello');
    $chunks = [];
    foreach ($stream1 as $message) {
        if ($message instanceof \LarAgent\Messages\StreamedAssistantMessage || $message instanceof \LarAgent\Messages\AssistantMessage) {
            $chunks[] = $message->getContentAsString();
        }
    }
    expect($chunks)->not->toBeEmpty();
    expect($agent->getActiveProviderName())->toBe('success');

    // Second call - should start from first provider again
    $stream2 = $agent->respondStreamed('Hello again');
    $chunks2 = [];
    foreach ($stream2 as $message) {
        if ($message instanceof \LarAgent\Messages\StreamedAssistantMessage || $message instanceof \LarAgent\Messages\AssistantMessage) {
            $chunks2[] = $message->getContentAsString();
        }
    }
    expect($chunks2)->not->toBeEmpty();
    expect($agent->getActiveProviderName())->toBe('success');
});
