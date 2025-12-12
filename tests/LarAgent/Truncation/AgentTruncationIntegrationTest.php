<?php

use LarAgent\Agent;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Message;

// Test Agent class for truncation testing
class TruncationTestAgent extends Agent
{
    protected $enableTruncation = true;
    protected $contextWindowSize = 10000;
    
    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        return new SimpleTruncationStrategy([
            'keep_messages' => 3,
            'preserve_system' => true,
        ]);
    }
}

describe('Agent Truncation Integration', function () {
    it('applies truncation when enabled via property', function () {
        $agent = TruncationTestAgent::for('test-truncation');
        
        expect($agent->shouldTruncate())->toBeTrue();
        expect($agent->getContextWindowSize())->toBe(10000);
    });

    it('does not apply truncation when disabled', function () {
        $agent = Agent::for('test-no-truncation');
        
        expect($agent->shouldTruncate())->toBeFalse();
    });

    it('can enable truncation at runtime', function () {
        $agent = Agent::for('test-runtime-truncation');
        
        expect($agent->shouldTruncate())->toBeFalse();
        
        $agent->enableTruncation(true);
        
        expect($agent->shouldTruncate())->toBeTrue();
    });

    it('uses provider config when agent property is null', function () {
        config(['laragent.providers.default.enable_truncation' => true]);
        
        $agent = Agent::for('test-provider-config');
        
        expect($agent->shouldTruncate())->toBeTrue();
        
        // Reset config
        config(['laragent.providers.default.enable_truncation' => null]);
    });

    it('uses global config when both agent and provider configs are null', function () {
        config(['laragent.enable_truncation' => true]);
        
        $agent = Agent::for('test-global-config');
        
        expect($agent->shouldTruncate())->toBeTrue();
        
        // Reset config
        config(['laragent.enable_truncation' => false]);
    });

    it('respects config priority: agent > provider > global', function () {
        // Set global and provider configs
        config(['laragent.enable_truncation' => true]);
        config(['laragent.providers.default.enable_truncation' => false]);
        
        // Agent with explicit property should override all configs
        $agent = TruncationTestAgent::for('test-priority');
        
        expect($agent->shouldTruncate())->toBeTrue();
        
        // Reset configs
        config(['laragent.enable_truncation' => false]);
        config(['laragent.providers.default.enable_truncation' => null]);
    });

    it('gets context window size from agent property', function () {
        $agent = TruncationTestAgent::for('test-window-size');
        
        expect($agent->getContextWindowSize())->toBe(10000);
    });

    it('gets context window size from provider config when agent property is null', function () {
        config(['laragent.providers.default.default_context_window' => 50000]);
        
        $agent = Agent::for('test-provider-window');
        
        expect($agent->getContextWindowSize())->toBe(50000);
    });

    it('context has truncation strategy when truncation is enabled', function () {
        $agent = TruncationTestAgent::for('test-context-strategy');
        
        $strategy = $agent->context()->getTruncationStrategy();
        
        expect($strategy)->toBeInstanceOf(SimpleTruncationStrategy::class);
    });

    it('context has context window size when truncation is enabled', function () {
        $agent = TruncationTestAgent::for('test-context-window');
        
        $windowSize = $agent->context()->getContextWindowSize();
        
        expect($windowSize)->toBe(10000);
    });
});
