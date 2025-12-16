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
            'default_truncation_threshold' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
    ][$key];
}

class WeatherTool extends LarAgent\Tool
{
    protected string $name = 'get_current_weather';

    protected string $description = 'Get the current weather in a given location';

    protected array $properties = [
        'location' => [
            'type' => 'string',
            'description' => 'The city and state, e.g. San Francisco, CA',
        ],
        'unit' => [
            'type' => 'string',
            'description' => 'The unit of temperature',
            'enum' => ['celsius', 'fahrenheit'],
        ],
    ];

    protected array $required = ['location'];

    protected array $metaData = ['sent_at' => '2024-01-01'];

    public function execute(array $input): mixed
    {
        // Call the weather API
        return 'The weather in '.$input['location'].' is '.rand(10, 60).' degrees '.$input['unit'];
    }
}

enum Unit: string
{
    case CELSIUS = 'celsius';
    case FAHRENHEIT = 'fahrenheit';
}

class WeatherAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    // protected $model = 'o1-2024-12-17';
    protected $model = 'gpt-4o-mini';

    // Tool by classes
    protected $tools = [
        // WeatherTool::class
    ];

    // To not saves chat keys to memory, by default = true
    protected $saveChatKeys = false;

    // Switch to developer role for instructions
    // protected $developerRoleForInstructions = true;

    protected $parallelToolCalls = null;

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
        // return view('ai.prompts.weather', ['message' => $message])->render();
    }

    // Define history with custom options or using custom history class
    public function createChatHistory($name)
    {
        return new LarAgent\History\JsonChatHistory($name, ['folder' => __DIR__.'/json_History', 'store_meta' => true]);
    }

    public function registerTools()
    {
        $user = ['location' => 'Tbilisi'];

        return [
            \LarAgent\Tool::create('user_location', "Returns user's current location")
                ->setCallback(function () use ($user) {
                    return $user['location'];
                }),
        ];
    }

    // Example of a tool defined as a method with optional and required parameters
    #[Tool('Get the current weather in a given location')]
    public function weatherTool($location, $unit = 'celsius')
    {
        echo "// Wheather tool called for $location // \n\n";

        return 'The weather in '.$location.' is '.'20'.' degrees '.$unit;
    }

    // @todo implement metadata support for tool attribute

    // Example of using static method as tool and all it's features
    // Tool Description, property descriptions, enums, required properties
    #[Tool('Get the current weather in a given location', ['unit' => 'Unit of temperature'])]
    public static function weatherToolForNewYork(Unit $unit)
    {
        return 'The weather in New York is '.'50'.' degrees '.$unit->value;
    }
}

// // Using "celsus" instead of "celsius" to check correct pick of enum value
// echo WeatherAgent::for('test_chat')->respond('Thanks for the info. What about New York? I prefer celsus');
// echo "\n---\n";
echo WeatherAgent::for('test_chat')->message('Where am I now?')->respond();
// echo "\n---\n";
// echo WeatherAgent::for('test_chat')->model();
