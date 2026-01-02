<?php

namespace LarAgent\BuiltIn\Agents;

use LarAgent\Agent;
use LarAgent\BuiltIn\DataModels\MessageSymbolsResponse;

class ChatSymbolizerAgent extends Agent
{
    protected $history = 'in_memory';

    protected $enableTruncation = false;

    protected $responseSchema = MessageSymbolsResponse::class;

    protected $storage = [
        \LarAgent\Context\Drivers\InMemoryStorage::class,
    ];

    /**
     * Get provider data using truncation_provider config.
     */
    protected function getProviderData(): ?array
    {
        $provider = config('laragent.truncation_provider', 'default');

        return config("laragent.providers.{$provider}");
    }

    /**
     * Get the instructions for the symbolizer agent.
     */
    public function instructions(): string
    {
        // Try to use Blade view if available
        if (function_exists('view') && app()->bound('view')) {
            try {
                return view('laragent::prompts.chat-symbolizer')->render();
            } catch (\Throwable $e) {
                // Fall through to file read
            }
        }

        // Read directly from the Blade file
        $bladeFile = dirname(__DIR__, 3).'/resources/views/prompts/chat-symbolizer.blade.php';
        if (file_exists($bladeFile)) {
            return file_get_contents($bladeFile);
        }

        // Ultimate fallback
        return 'You are a message symbolizer. Create brief 10-word summaries of messages.';
    }
}
