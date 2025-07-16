<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Attributes\Tool;
use LarAgent\PhantomTool;

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

function phantomTool($location)
{
    return 'The weather in '.$location.' is '.'20'.' degrees Celsius';
}

class WeatherAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function instructions()
    {
        $user = ['name' => 'John', 'age' => 25];

        return
            "You are weather agent holding info about weather in any city.
            Always use User's name while responding.
            User info: ".json_encode($user);
    }

    public function registerTools() {
        return [
            PhantomTool::create('phantom_tool', 'Get the current weather in a given location')
                ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
                ->setRequired('location')
                ->setCallback("phantomTool"),
        ];
    }

    public function prompt($message)
    {
        return $message.'. Always check if I have other questions.';
    }
}


$response = WeatherAgent::for('test_chat')->forceTool('phantom_tool')->respond('What is weather in New York?');
echo "\n---\n";
var_dump($response);
echo "\n---\n";
