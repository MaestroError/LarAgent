<?php

use LarAgent\Agent;
use LarAgent\Context\SessionIdentity;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

/**
 * Base test agent class that provides common functionality.
 */
abstract class BaseIdentityTestAgent extends Agent
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

// Test agent without any identity overrides (for backward compatibility testing)
class DefaultIdentityAgent2 extends BaseIdentityTestAgent {}

// Test agents with custom history identity - using different group names
class TeamAlphaHistoryAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'team-alpha',
        );
    }
}

class SharedGroupHistoryAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'shared-group',
        );
    }
}

class GroupAHistoryAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'group-a',
        );
    }
}

class GroupBHistoryAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'group-b',
        );
    }
}

// Test agents with custom usage identity - using different organization IDs
class Org123UsageAgent extends BaseIdentityTestAgent
{
    protected function createUsageIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'org-123',
        );
    }
}

class OrgSharedUsageAgent extends BaseIdentityTestAgent
{
    protected function createUsageIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'org-shared',
        );
    }
}

class OrgTrackingUsageAgent extends BaseIdentityTestAgent
{
    protected function createUsageIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'org-tracking-test',
        );
    }
}

// Test agents with both history and usage identity overrides
class DualIdentityAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'history-scope',
        );
    }

    protected function createUsageIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'usage-scope',
        );
    }
}

class TeamBillingDualAgent extends BaseIdentityTestAgent
{
    protected function createHistoryIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'team-history',
        );
    }

    protected function createUsageIdentity(): \LarAgent\Context\Contracts\SessionIdentity
    {
        return new SessionIdentity(
            agentName: $this->name(),
            chatName: 'org-billing',
        );
    }
}

describe('Storage Identity Override - History Identity', function () {
    it('can override createHistoryIdentity for custom history scoping', function () {
        $agent = TeamAlphaHistoryAgent::for('user1');

        // The chat history should use the custom group-based identity
        $historyIdentifier = $agent->chatHistory()->getIdentifier();

        // The identifier should contain the custom group, not the original session id
        expect($historyIdentifier)->toContain('team-alpha');
    });

    it('allows different users to share history via custom identity', function () {
        // Two agents using the same class will share the same history identity
        $agent1 = SharedGroupHistoryAgent::for('user1');
        $agent2 = SharedGroupHistoryAgent::for('user2');

        // Both agents should have the same history identifier since they share the group
        expect($agent1->chatHistory()->getIdentifier())->toBe($agent2->chatHistory()->getIdentifier());
    });

    it('isolates history when custom groups differ', function () {
        $agent1 = GroupAHistoryAgent::for('user1');
        $agent2 = GroupBHistoryAgent::for('user1');

        // Agents should have different history identifiers
        expect($agent1->chatHistory()->getIdentifier())->not->toBe($agent2->chatHistory()->getIdentifier());
    });
});

describe('Storage Identity Override - Usage Identity', function () {
    it('can override createUsageIdentity for custom usage tracking', function () {
        $agent = Org123UsageAgent::for('user1');

        // The usage storage should use the organization-based identity
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // The identifier should contain the organization ID
        expect($usageIdentifier)->toContain('org-123');
    });

    it('allows different users to share usage tracking via custom identity', function () {
        // Two agents using the same class will share the same usage identity
        $agent1 = OrgSharedUsageAgent::for('user1');
        $agent2 = OrgSharedUsageAgent::for('user2');

        // Both agents should have the same usage identifier
        expect($agent1->usageStorage()->getIdentifier())->toBe($agent2->usageStorage()->getIdentifier());
    });

    it('tracks usage correctly with custom identity', function () {
        $agent = OrgTrackingUsageAgent::for('user-'.uniqid());

        $agent->respond('Hello');

        $usage = $agent->getUsage();
        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
        expect($usage->getTotalTokens())->toBe(150);
    });
});

describe('Storage Identity Override - Dual Identity Override', function () {
    it('allows different identities for history and usage', function () {
        $agent = DualIdentityAgent::for('user1');

        $historyIdentifier = $agent->chatHistory()->getIdentifier();
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // History and usage should have different identifiers
        expect($historyIdentifier)->not->toBe($usageIdentifier);
        expect($historyIdentifier)->toContain('history-scope');
        expect($usageIdentifier)->toContain('usage-scope');
    });

    it('tracks history and usage independently with different scopes', function () {
        $agent = TeamBillingDualAgent::for('session-'.uniqid());

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
        $agent = DefaultIdentityAgent2::for($sessionId);

        // By default, both history and usage should use the context identity
        $historyIdentifier = $agent->chatHistory()->getIdentifier();
        $usageIdentifier = $agent->usageStorage()->getIdentifier();

        // Both should contain the session ID and agent name from context identity
        expect($historyIdentifier)->toContain($sessionId);
        expect($usageIdentifier)->toContain($sessionId);
    });

    it('default agent behavior is unchanged when not overriding identity methods', function () {
        $agent = DefaultIdentityAgent2::for('compatibility-test');

        // Ensure basic operations work
        $agent->respond('Hello');

        $messages = $agent->chatHistory()->getMessages();
        expect(count($messages))->toBeGreaterThan(0);

        $usage = $agent->getUsage();
        expect($usage)->not->toBeNull();
        expect($usage->count())->toBe(1);
    });
});
