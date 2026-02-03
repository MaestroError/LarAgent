<?php

use LarAgent\Agent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

/**
 * Creates a history identity agent class that uses a specific custom group.
 * Since identity is determined at construction time, we need to create agents
 * with the group already defined in the class.
 */
function createHistoryIdentityAgentClass(string $customGroup): string
{
    $className = 'CustomHistoryIdentityAgent_'.uniqid();
    eval("
        class {$className} extends \\LarAgent\\Agent
        {
            protected \$model = 'gpt-4';
            protected \$history = 'in_memory';
            protected \$driver = \\LarAgent\\Tests\\LarAgent\\Fakes\\FakeLlmDriver::class;

            public function instructions()
            {
                return 'You are a test agent.';
            }

            protected function onInitialize()
            {
                \$this->llmDriver->addMockResponse('stop', [
                    'content' => 'Hello!',
                ]);
            }

            protected function createHistoryIdentity(): \\LarAgent\\Context\\Contracts\\SessionIdentity
            {
                return new \\LarAgent\\Context\\SessionIdentity(
                    agentName: \$this->name(),
                    chatName: '{$customGroup}',
                );
            }
        }
    ");

    return $className;
}

/**
 * Creates a usage identity agent class that uses a specific organization ID.
 */
function createUsageIdentityAgentClass(string $organizationId): string
{
    $className = 'CustomUsageIdentityAgent_'.uniqid();
    eval("
        class {$className} extends \\LarAgent\\Agent
        {
            protected \$model = 'gpt-4';
            protected \$history = 'in_memory';
            protected \$driver = \\LarAgent\\Tests\\LarAgent\\Fakes\\FakeLlmDriver::class;
            protected \$trackUsage = true;
            protected \$usageStorage = 'in_memory';

            public function instructions()
            {
                return 'You are a test agent.';
            }

            protected function onInitialize()
            {
                \$this->llmDriver->addMockResponse('stop', [
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

            protected function createUsageIdentity(): \\LarAgent\\Context\\Contracts\\SessionIdentity
            {
                return new \\LarAgent\\Context\\SessionIdentity(
                    agentName: \$this->name(),
                    chatName: '{$organizationId}',
                );
            }
        }
    ");

    return $className;
}

/**
 * Creates an agent class with both history and usage identity overrides.
 */
function createDualIdentityAgentClass(string $historyGroup, string $usageGroup): string
{
    $className = 'CustomDualIdentityAgent_'.uniqid();
    eval("
        class {$className} extends \\LarAgent\\Agent
        {
            protected \$model = 'gpt-4';
            protected \$history = 'in_memory';
            protected \$driver = \\LarAgent\\Tests\\LarAgent\\Fakes\\FakeLlmDriver::class;
            protected \$trackUsage = true;
            protected \$usageStorage = 'in_memory';

            public function instructions()
            {
                return 'You are a test agent.';
            }

            protected function onInitialize()
            {
                \$this->llmDriver->addMockResponse('stop', [
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

            protected function createHistoryIdentity(): \\LarAgent\\Context\\Contracts\\SessionIdentity
            {
                return new \\LarAgent\\Context\\SessionIdentity(
                    agentName: \$this->name(),
                    chatName: '{$historyGroup}',
                );
            }

            protected function createUsageIdentity(): \\LarAgent\\Context\\Contracts\\SessionIdentity
            {
                return new \\LarAgent\\Context\\SessionIdentity(
                    agentName: \$this->name(),
                    chatName: '{$usageGroup}',
                );
            }
        }
    ");

    return $className;
}

// Test agent without any identity overrides (for backward compatibility testing)
class DefaultIdentityAgent extends Agent
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
}

describe('Storage Identity Override - History Identity', function () {
    it('can override createHistoryIdentity for custom history scoping', function () {
        $agentClass = createHistoryIdentityAgentClass('team-alpha');
        $agent = $agentClass::for('user1');

        // The chat history should use the custom group-based identity
        $historyIdentifier = $agent->chatHistory()->getIdentifier();

        // The identifier should contain the custom group, not the original session id
        expect($historyIdentifier)->toContain('team-alpha');
    });

    it('allows different users to share history via custom identity', function () {
        // Create the same agent class for both users (same custom group)
        $agentClass = createHistoryIdentityAgentClass('shared-group');

        $agent1 = $agentClass::for('user1');
        $agent2 = $agentClass::for('user2');

        // Both agents should have the same history identifier since they share the group
        expect($agent1->chatHistory()->getIdentifier())->toBe($agent2->chatHistory()->getIdentifier());
    });

    it('isolates history when custom groups differ', function () {
        $agentClass1 = createHistoryIdentityAgentClass('group-a');
        $agentClass2 = createHistoryIdentityAgentClass('group-b');

        $agent1 = $agentClass1::for('user1');
        $agent2 = $agentClass2::for('user1');

        // Agents should have different history identifiers
        expect($agent1->chatHistory()->getIdentifier())->not->toBe($agent2->chatHistory()->getIdentifier());
    });
});

describe('Storage Identity Override - Usage Identity', function () {
    it('can override createUsageIdentity for custom usage tracking', function () {
        $agentClass = createUsageIdentityAgentClass('org-123');
        $agent = $agentClass::for('user1');

        // The usage storage should use the organization-based identity
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // The identifier should contain the organization ID
        expect($usageIdentifier)->toContain('org-123');
    });

    it('allows different users to share usage tracking via custom identity', function () {
        // Create the same agent class for both users (same organization)
        $agentClass = createUsageIdentityAgentClass('org-shared');

        $agent1 = $agentClass::for('user1');
        $agent2 = $agentClass::for('user2');

        // Both agents should have the same usage identifier
        expect($agent1->usageStorage()->getIdentifier())->toBe($agent2->usageStorage()->getIdentifier());
    });

    it('tracks usage correctly with custom identity', function () {
        $agentClass = createUsageIdentityAgentClass('org-tracking-test');
        $agent = $agentClass::for('user-'.uniqid());

        $agent->respond('Hello');

        $usage = $agent->getUsage();
        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
        expect($usage->getTotalTokens())->toBe(150);
    });
});

describe('Storage Identity Override - Dual Identity Override', function () {
    it('allows different identities for history and usage', function () {
        $agentClass = createDualIdentityAgentClass('history-scope', 'usage-scope');
        $agent = $agentClass::for('user1');

        $historyIdentifier = $agent->chatHistory()->getIdentifier();
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // History and usage should have different identifiers
        expect($historyIdentifier)->not->toBe($usageIdentifier);
        expect($historyIdentifier)->toContain('history-scope');
        expect($usageIdentifier)->toContain('usage-scope');
    });

    it('tracks history and usage independently with different scopes', function () {
        $agentClass = createDualIdentityAgentClass('team-history', 'org-billing');
        $agent = $agentClass::for('session-'.uniqid());

        $agent->respond('Test message');

        // Verify history was tracked with history identity
        $messages = $agent->chatHistory()->getMessages();
        expect($messages)->not->toBeEmpty();

        // Verify usage was tracked with usage identity
        $usage = $agent->getUsage();
        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
    });
});

describe('Storage Identity Override - Backward Compatibility', function () {
    it('default agent uses context identity for both storages', function () {
        $sessionId = 'test-session-'.uniqid();
        $agent = DefaultIdentityAgent::for($sessionId);

        // By default, both history and usage should use the context identity
        $historyIdentifier = $agent->chatHistory()->getIdentifier();
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // Both should contain the session ID and agent name from context identity
        expect($historyIdentifier)->toContain($sessionId);
        expect($usageIdentifier)->toContain($sessionId);
    });

    it('default agent behavior is unchanged when not overriding identity methods', function () {
        $agent = DefaultIdentityAgent::for('compatibility-test');

        // Ensure basic operations work
        $agent->respond('Hello');

        $messages = $agent->chatHistory()->getMessages();
        expect(count($messages))->toBeGreaterThan(0);

        $usage = $agent->getUsage();
        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
    });
});
