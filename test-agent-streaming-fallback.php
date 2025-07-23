<?php

require_once __DIR__.'/vendor/autoload.php';

function config(string $key): mixed
{
    $yourApiKey = include 'openai-api-key.php';

    return [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.providers.failing' => [
            'label' => 'openai',
            'model' => 'gpt-4.1-nano-bad-name', // Error in model name
            'api_key' => $yourApiKey,
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
        'laragent.providers.success' => [
            'label' => 'openai',
            'model' => 'gpt-4.1-nano-2025-04-14',
            'api_key' => $yourApiKey,
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
        'laragent.fallback_provider' => 'success',
    ][$key];
}

class WeatherAgent extends LarAgent\Agent
{
    protected $provider = 'failing';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are weather agent holding info about weather in any city.';
    }

    public function prompt($message)
    {
        return $message.'. Always check if I have other questions.';
    }

    // Define history with custom options or using custom history class
    public function createChatHistory($name)
    {
        return new LarAgent\History\JsonChatHistory($name, ['folder' => __DIR__.'/json_History', 'store_meta' => true]);
    }
}

$stream = WeatherAgent::for('test_chat')
    ->respondStreamed(
        'What\'s is my current location?'
    );

foreach ($stream as $chunk) {
    if ($chunk instanceof \LarAgent\Messages\StreamedAssistantMessage) {
        echo $chunk->getLastChunk();
    }
}
