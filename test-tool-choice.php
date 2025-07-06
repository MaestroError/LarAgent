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


enum Unit: string
{
    case CELSIUS = 'celsius';
    case FAHRENHEIT = 'fahrenheit';
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

    public function prompt($message)
    {
        return $message.'. Always check if I have other questions.';
    }

    #[Tool('Get the current weather in a given location')]
    public function weatherTool($location, $unit = 'celsius')
    {
        echo "// Wheather tool called for $location // \n\n";

        return 'The weather in '.$location.' is '.'20'.' degrees '.$unit;
    }

    #[Tool('Get the current weather in a given location', ['unit' => 'Unit of temperature'])]
    public static function weatherToolForNewYork(Unit $unit)
    {
        echo "New York tool";
        return 'The weather in New York is '.'50'.' degrees '.$unit->value;
    }
}


echo WeatherAgent::for('test_chat')->toolRequired()->respond('Who is president of US?');
echo "\n---\n";
echo WeatherAgent::for('test_chat')->toolNone()->respond('What is weather in New York?');
echo "\n---\n";
echo WeatherAgent::for('test_chat')->forceTool('weatherToolForNewYork')->respond('What is weather in New York?');
echo "\n---\n";

