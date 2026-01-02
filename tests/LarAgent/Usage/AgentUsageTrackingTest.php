<?php

use LarAgent\Agent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Usage\UsageStorage;

// Test agent with usage tracking enabled
class UsageTrackingAgent extends Agent
{
    protected $model = 'gpt-4';

    protected $history = 'in_memory';

    protected $driver = FakeLlmDriver::class;

    protected $trackUsage = true;

    protected $usageStorage = 'in_memory';

    public function instructions()
    {
        return 'You are a test agent.';
    }

    protected function onInitialize()
    {
        // Setup mock response with usage data
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello!',
            'metaData' => [
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'total_tokens' => 150,
                ],
            ],
        ]);
    }

    // Add method to add more mock responses for multi-response tests
    public function addMockResponseWithUsage(array $usage)
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Response!',
            'metaData' => [
                'usage' => $usage,
            ],
        ]);
    }
}

// Test agent without usage tracking
class NoUsageTrackingAgent extends Agent
{
    protected $model = 'gpt-4';

    protected $history = 'in_memory';

    protected $driver = FakeLlmDriver::class;

    protected $trackUsage = false;

    public function instructions()
    {
        return 'You are a test agent.';
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello!',
        ]);
    }
}

describe('Agent Usage Tracking Integration', function () {
    it('can enable usage tracking via property', function () {
        $agent = UsageTrackingAgent::for('test-session');

        expect($agent->shouldTrackUsage())->toBeTrue();
        expect($agent->usageStorage())->not->toBeNull();
        expect($agent->usageStorage())->toBeInstanceOf(UsageStorage::class);
    });

    it('does not track usage when disabled', function () {
        $agent = NoUsageTrackingAgent::for('test-session');

        expect($agent->shouldTrackUsage())->toBeFalse();
        expect($agent->usageStorage())->toBeNull();
    });

    it('can enable usage tracking via method', function () {
        $agent = NoUsageTrackingAgent::for('test-session');

        expect($agent->shouldTrackUsage())->toBeFalse();

        $agent->trackUsage(true);

        expect($agent->shouldTrackUsage())->toBeTrue();
        expect($agent->usageStorage())->not->toBeNull();
    });

    it('tracks usage after response', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        // Make a request
        $response = $agent->respond('Hello');

        // Check usage was tracked
        $usage = $agent->getUsage();

        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
        expect($usage->getTotalPromptTokens())->toBe(100);
        expect($usage->getTotalCompletionTokens())->toBe(50);
        expect($usage->getTotalTokens())->toBe(150);
    });

    it('stores correct metadata with usage records', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        $response = $agent->respond('Hello');

        $usage = $agent->getUsage();
        $record = $usage->first();

        expect($record->agentName)->toBe('UsageTrackingAgent');
        expect($record->modelName)->toBe('gpt-4');
    });

    it('can filter usage records', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        // Add additional mock responses before calling respond
        $agent->addMockResponseWithUsage([
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'total_tokens' => 300,
        ]);

        // Multiple responses
        $agent->respond('First message');
        $agent->respond('Second message');

        // Get all usage
        $allUsage = $agent->getUsage();
        expect($allUsage->count())->toBe(2);

        // Filter by agent name
        $filtered = $agent->getUsage(['agent_name' => 'UsageTrackingAgent']);
        expect($filtered->count())->toBe(2);

        // Filter by non-existent agent
        $filtered = $agent->getUsage(['agent_name' => 'NonExistent']);
        expect($filtered->count())->toBe(0);
    });

    it('can get aggregated usage', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        // Add additional mock response before calling respond
        $agent->addMockResponseWithUsage([
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'total_tokens' => 300,
        ]);

        $agent->respond('First message');
        $agent->respond('Second message');

        $aggregate = $agent->getUsageAggregate();

        expect($aggregate)->not->toBeNull();
        expect($aggregate['total_prompt_tokens'])->toBe(300);
        expect($aggregate['total_completion_tokens'])->toBe(150);
        expect($aggregate['total_tokens'])->toBe(450);
        expect($aggregate['record_count'])->toBe(2);
    });

    it('can get usage grouped by field', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        $agent->respond('First message');

        $grouped = $agent->getUsageGroupedBy('agent_name');

        expect($grouped)->not->toBeNull();
        expect($grouped)->toHaveKey('UsageTrackingAgent');
        expect($grouped['UsageTrackingAgent']['record_count'])->toBe(1);
    });

    it('can clear usage records', function () {
        $agent = UsageTrackingAgent::for('test-session-'.uniqid());

        $agent->respond('First message');
        expect($agent->getUsage()->count())->toBe(1);

        $agent->clearUsage();
        expect($agent->getUsage()->count())->toBe(0);
    });

    it('returns null for usage methods when tracking is disabled', function () {
        $agent = NoUsageTrackingAgent::for('test-session');

        expect($agent->getUsage())->toBeNull();
        expect($agent->getUsageAggregate())->toBeNull();
        expect($agent->getUsageGroupedBy('agent_name'))->toBeNull();
    });
});

describe('Agent Usage Storage Configuration', function () {
    it('uses in_memory storage driver when configured', function () {
        $agent = UsageTrackingAgent::for('test-session');
        $storage = $agent->usageStorage();

        expect($storage)->not->toBeNull();
        expect($storage->getStoragePrefix())->toBe('usage');
    });

    it('respects provider-level track_usage config', function () {
        // Set provider-level config to enable tracking
        config()->set('laragent.providers.default.track_usage', true);
        config()->set('laragent.track_usage', false); // Global disabled

        // Debug: verify config is set correctly
        expect(config('laragent.providers.default.track_usage'))->toBeTrue();
        expect(config('laragent.track_usage'))->toBeFalse();

        $agent = new class('test-session-provider-'.uniqid()) extends Agent
        {
            protected $model = 'gpt-4';

            protected $history = 'in_memory';

            protected $driver = FakeLlmDriver::class;

            protected $provider = 'default';

            // Note: $trackUsage is left as null (default), so it uses config

            protected $usageStorage = 'in_memory';

            public function instructions()
            {
                return 'Test agent';
            }

            protected function onInitialize()
            {
                $this->llmDriver->addMockResponse('stop', ['content' => 'Hello!']);
            }
        };

        // Provider config should override global config
        expect($agent->shouldTrackUsage())->toBeTrue();
        expect($agent->usageStorage())->not->toBeNull();

        // Clean up
        config()->set('laragent.providers.default.track_usage', null);
    });

    it('agent property takes precedence over provider config', function () {
        // Set provider-level config to enable tracking
        config()->set('laragent.providers.default.track_usage', true);

        $agent = new class('test-session-precedence-'.uniqid()) extends Agent
        {
            protected $model = 'gpt-4';

            protected $history = 'in_memory';

            protected $driver = FakeLlmDriver::class;

            protected $provider = 'default';

            protected $trackUsage = false; // Explicitly disabled at agent level

            public function instructions()
            {
                return 'Test agent';
            }

            protected function onInitialize()
            {
                $this->llmDriver->addMockResponse('stop', ['content' => 'Hello!']);
            }
        };

        // Agent property should override provider config
        expect($agent->shouldTrackUsage())->toBeFalse();

        // Clean up
        config()->set('laragent.providers.default.track_usage', null);
    });

    it('can create custom usage storage via createUsageStorage override', function () {
        // Create inline class that overrides createUsageStorage
        $agent = new class('test-session') extends Agent
        {
            protected $model = 'gpt-4';

            protected $history = 'in_memory';

            protected $driver = FakeLlmDriver::class;

            protected $trackUsage = true;

            public $customStorageCreated = false;

            public function instructions()
            {
                return 'Test agent';
            }

            protected function onInitialize()
            {
                $this->llmDriver->addMockResponse('stop', ['content' => 'Hello!']);
            }

            public function createUsageStorage(): UsageStorage
            {
                $this->customStorageCreated = true;

                return new UsageStorage(
                    $this->context()->getIdentity(),
                    [\LarAgent\Context\Drivers\InMemoryStorage::class],
                    $this->model(),
                    $this->providerName
                );
            }
        };

        expect($agent->customStorageCreated)->toBeTrue();
        expect($agent->usageStorage())->not->toBeNull();
    });

    it('supports string alias for usage_storage in provider config', function () {
        // Set provider-level config with string alias
        config()->set('laragent.providers.default.usage_storage', 'in_memory');
        config()->set('laragent.providers.default.track_usage', true);

        $agent = new class('test-session') extends Agent
        {
            protected $model = 'gpt-4';

            protected $history = 'in_memory';

            protected $driver = FakeLlmDriver::class;

            // No usageStorage property - should use provider config

            public function instructions()
            {
                return 'Test agent';
            }

            protected function onInitialize()
            {
                $this->llmDriver->addMockResponse('stop', [
                    'content' => 'Hello!',
                    'metaData' => [
                        'usage' => [
                            'prompt_tokens' => 100,
                            'completion_tokens' => 50,
                            'total_tokens' => 150,
                        ],
                    ],
                ]);
            }
        };

        // Should resolve 'in_memory' string to InMemoryStorage driver
        expect($agent->shouldTrackUsage())->toBeTrue();
        expect($agent->usageStorage())->not->toBeNull();

        $agent->respond('Test');
        expect($agent->getUsage()->count())->toBe(1);

        // Clean up
        config()->set('laragent.providers.default.usage_storage', null);
        config()->set('laragent.providers.default.track_usage', null);
    });

    it('supports string alias for default_usage_storage in global config', function () {
        // Set global config with string alias
        config()->set('laragent.default_usage_storage', 'in_memory');

        $agent = new class('test-session') extends Agent
        {
            protected $model = 'gpt-4';

            protected $history = 'in_memory';

            protected $driver = FakeLlmDriver::class;

            protected $trackUsage = true;

            // No usageStorage property - should use global config

            public function instructions()
            {
                return 'Test agent';
            }

            protected function onInitialize()
            {
                $this->llmDriver->addMockResponse('stop', [
                    'content' => 'Hello!',
                    'metaData' => [
                        'usage' => [
                            'prompt_tokens' => 100,
                            'completion_tokens' => 50,
                            'total_tokens' => 150,
                        ],
                    ],
                ]);
            }
        };

        expect($agent->usageStorage())->not->toBeNull();

        $agent->respond('Test');
        expect($agent->getUsage()->count())->toBe(1);

        // Clean up
        config()->set('laragent.default_usage_storage', null);
    });
});
