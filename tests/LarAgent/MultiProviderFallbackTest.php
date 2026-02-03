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
 */
class DefaultProvidersAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    // Not setting $provider to test default_providers config
    protected $provider = 'default';

    public function instructions()
    {
        return 'Default providers test agent.';
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

it('uses default_providers from config when agent provider is default', function () {
    // Configure default_providers
    config()->set('laragent.default_providers', ['fail', 'success']);

    $agent = new DefaultProvidersAgent('test_key');

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

    $agent = new DefaultProvidersAgent('test_key');

    // Should fall back to success provider with override
    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
    expect($agent->model())->toBe('config-override-model');
});

// ==================== Provider Name Tests ====================

it('returns correct provider name after fallback', function () {
    $agent = new MultiProviderFallbackTestAgent('test_key');

    // Initial provider
    expect($agent->getProviderName())->toBe('fail');

    // After respond (which triggers fallback)
    $agent->respond('Hello');

    // Now should be the success provider
    expect($agent->getProviderName())->toBe('success');
});
