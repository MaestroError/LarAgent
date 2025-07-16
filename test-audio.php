<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Attributes\Tool;

function config(string $key): mixed
{
    $yourApiKey = include 'openai-api-key.php';

    return [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.providers.default' => [
            'label' => 'openai',
            'model' => 'gpt-4o',
            'api_key' => $yourApiKey,
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
    ][$key];
}

class WeatherAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-audio-preview-2025-06-03';

    protected $history = 'in_memory';

    public function instructions()
    {
        $user = ['name' => 'John', 'age' => 25];

        return
            "You are weather agent holding info about weather in any city.
            Always use User's name while responding.
            User info: ".json_encode($user);
    }

    public function prompt($message)
    {
        return $message.'. Always check if I have other questions.';
    }
}

// @todo test generateAudio method after releaseing this fix: https://github.com/openai-php/client/issues/627
echo WeatherAgent::for('test_chat')->generateAudio('mp3', 'nova')->respond('What is my name?');