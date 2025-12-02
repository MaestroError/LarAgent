<?php

/**
 * Usage Data Tests for All Providers
 * 
 * Tests that all LLM providers correctly return usage/token data
 * from both regular and streamed responses.
 * 
 * To run these tests, you need to configure API keys for each provider
 * in their respective api-key.php files.
 */

use LarAgent\Agent;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\Gemini\GeminiDriver;
use LarAgent\Drivers\Groq\GroqDriver;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Drivers\OpenAi\OpenRouter;
use LarAgent\Messages\DataModels\Usage;
use LarAgent\Tests\TestCase;

uses(TestCase::class);

// ============================================================================
// Test Agents for Each Provider
// ============================================================================

class OpenAiUsageTestAgent extends Agent
{
    protected $provider = 'openai';
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

class ClaudeUsageTestAgent extends Agent
{
    protected $provider = 'claude';
    protected $model = 'claude-3-5-haiku-latest';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

class GeminiUsageTestAgent extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.0-flash-lite';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

class GroqUsageTestAgent extends Agent
{
    protected $provider = 'groq';
    protected $model = 'llama-3.3-70b-versatile';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

class OpenRouterUsageTestAgent extends Agent
{
    protected $provider = 'openrouter';
    protected $model = 'x-ai/grok-4.1-fast:free';
    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant. Keep responses brief.';
    }

    public function prompt($message)
    {
        return $message;
    }
}

// ============================================================================
// OpenAI Usage Tests
// ============================================================================

describe('OpenAI Usage Data', function () {
    beforeEach(function () {
        $apiKey = include __DIR__ . '/../openai-api-key.php';
        
        config()->set('laragent.providers.openai', [
            'label' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $apiKey,
            'driver' => OpenAiDriver::class,
        ]);
    });

    it('returns usage data from regular response', function () {
        $agent = OpenAiUsageTestAgent::for('openai_usage_test');
        
        $response = $agent->respond('Say "Hello"');
        
        // Get usage from the agent's last message
        $lastMessage = $agent->lastMessage();
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nOpenAI Usage (regular): " . json_encode($usage->toArray()) . "\n";
    });

    it('returns usage data from streamed response', function () {
        $agent = OpenAiUsageTestAgent::for('openai_usage_streamed_test');
        
        $stream = $agent->respondStreamed('Say "Hello"');
        
        // Consume the stream
        $lastMessage = null;
        foreach ($stream as $message) {
            $lastMessage = $message;
        }
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nOpenAI Usage (streamed): " . json_encode($usage->toArray()) . "\n";
    });
});

// ============================================================================
// Claude Usage Tests
// ============================================================================

describe('Claude Usage Data', function () {
    beforeEach(function () {
        $apiKey = include __DIR__ . '/anthropic-api-key.php';
        
        config()->set('laragent.providers.claude', [
            'label' => 'claude',
            'model' => 'claude-3-5-haiku-latest',
            'api_key' => $apiKey,
            'driver' => ClaudeDriver::class,
        ]);
    });

    it('returns usage data from regular response', function () {
        $agent = ClaudeUsageTestAgent::for('claude_usage_test');
        
        $response = $agent->respond('Say "Hello"');
        
        // Get usage from the agent's last message
        $lastMessage = $agent->lastMessage();
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nClaude Usage (regular): " . json_encode($usage->toArray()) . "\n";
    });

    it('returns usage data from streamed response', function () {
        $agent = ClaudeUsageTestAgent::for('claude_usage_streamed_test');
        
        $stream = $agent->respondStreamed('Say "Hello"');
        
        // Consume the stream
        $lastMessage = null;
        foreach ($stream as $message) {
            $lastMessage = $message;
        }
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nClaude Usage (streamed): " . json_encode($usage->toArray()) . "\n";
    });
});

// ============================================================================
// Gemini Usage Tests
// ============================================================================

describe('Gemini Usage Data', function () {
    beforeEach(function () {
        $apiKey = include __DIR__ . '/gemini-api-key.php';
        
        config()->set('laragent.providers.gemini', [
            'label' => 'gemini',
            'model' => 'gemini-2.0-flash-lite',
            'api_key' => $apiKey,
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'driver' => GeminiDriver::class,
        ]);
    });

    it('returns usage data from regular response', function () {
        $agent = GeminiUsageTestAgent::for('gemini_usage_test');
        
        $response = $agent->respond('Say "Hello"');
        
        // Get usage from the agent's last message
        $lastMessage = $agent->lastMessage();
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nGemini Usage (regular): " . json_encode($usage->toArray()) . "\n";
    });

    it('returns usage data from streamed response', function () {
        $agent = GeminiUsageTestAgent::for('gemini_usage_streamed_test');
        
        $stream = $agent->respondStreamed('Say "Hello"');
        
        // Consume the stream
        $lastMessage = null;
        foreach ($stream as $message) {
            $lastMessage = $message;
        }
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nGemini Usage (streamed): " . json_encode($usage->toArray()) . "\n";
    });
});

// ============================================================================
// Groq Usage Tests
// ============================================================================

describe('Groq Usage Data', function () {
    beforeEach(function () {
        $apiKey = include __DIR__ . '/groq-api-key.php';
        
        config()->set('laragent.providers.groq', [
            'label' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'api_key' => $apiKey,
            'driver' => GroqDriver::class,
        ]);
    });

    it('returns usage data from regular response', function () {
        $agent = GroqUsageTestAgent::for('groq_usage_test');
        
        $response = $agent->respond('Say "Hello"');
        
        // Get usage from the agent's last message
        $lastMessage = $agent->lastMessage();
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nGroq Usage (regular): " . json_encode($usage->toArray()) . "\n";
    });

    it('returns usage data from streamed response', function () {
        $agent = GroqUsageTestAgent::for('groq_usage_streamed_test');
        
        $stream = $agent->respondStreamed('Say "Hello"');
        
        // Consume the stream
        $lastMessage = null;
        foreach ($stream as $message) {
            $lastMessage = $message;
        }
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nGroq Usage (streamed): " . json_encode($usage->toArray()) . "\n";
    });
});

// ============================================================================
// OpenRouter Usage Tests
// ============================================================================

describe('OpenRouter Usage Data', function () {
    beforeEach(function () {
        $apiKey = include __DIR__ . '/openrouter-api-key.php';
        
        config()->set('laragent.providers.openrouter', [
            'label' => 'openrouter',
            'model' => 'deepseek/deepseek-chat-v3.1:free',
            'api_key' => $apiKey,
            'driver' => OpenRouter::class,
            'referer' => 'https://laragent.ai/',
            'title' => 'LarAgent',
        ]);
    });

    it('returns usage data from regular response', function () {
        $agent = OpenRouterUsageTestAgent::for('openrouter_usage_test');
        
        $response = $agent->respond('Say "Hello"');
        
        // Get usage from the agent's last message
        $lastMessage = $agent->lastMessage();
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nOpenRouter Usage (regular): " . json_encode($usage->toArray()) . "\n";
    });

    it('returns usage data from streamed response', function () {
        $agent = OpenRouterUsageTestAgent::for('openrouter_usage_streamed_test');
        
        $stream = $agent->respondStreamed('Say "Hello"');
        
        // Consume the stream
        $lastMessage = null;
        foreach ($stream as $message) {
            $lastMessage = $message;
        }
        
        expect($lastMessage)->not->toBeNull();
        
        $usage = $lastMessage->getUsage();
        
        expect($usage)->toBeInstanceOf(Usage::class);
        expect($usage->promptTokens)->toBeGreaterThan(0);
        expect($usage->completionTokens)->toBeGreaterThan(0);
        expect($usage->totalTokens)->toBe($usage->promptTokens + $usage->completionTokens);
        
        echo "\nOpenRouter Usage (streamed): " . json_encode($usage->toArray()) . "\n";
    });
});
