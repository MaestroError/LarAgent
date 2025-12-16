<?php

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Attributes\Tool;

// Helper function to simulate config retrieval
function config(string $key): mixed
{
    $yourApiKey = include __DIR__.'/../openai-api-key.php';

    return [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.providers.default' => [
            'label' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $yourApiKey,
            'default_truncation_threshold' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
    ][$key];
}

/**
 * Example of a tool class implementation
 */
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

    protected array $metaData = ['checked_at' => '2024-04-25'];

    public function execute(array $input): mixed
    {
        // Simulate weather API call
        $temperature = rand(10, 30);

        return "The weather in {$input['location']} is {$temperature} degrees {$input['unit']}";
    }
}

/**
 * Example enum for temperature units
 */
enum Unit: string
{
    case CELSIUS = 'celsius';
    case FAHRENHEIT = 'fahrenheit';
}

/**
 * Example agent class implementation
 */
class WeatherAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    // Tool by classes
    protected $tools = [
        // WeatherTool::class
    ];

    // To not save chat keys to memory, by default = true
    protected $saveChatKeys = false;

    protected $parallelToolCalls = null;

    protected $history = 'in_memory';

    /**
     * Define system instructions for the agent
     */
    public function instructions()
    {
        $user = ['name' => 'John', 'age' => 25];

        return
            "You are a weather agent providing information about weather in any city.
            Always use the user's name ({$user['name']}) while responding.
            Be concise and friendly in your responses.";
    }

    /**
     * Modify the user's message before sending it to the LLM
     */
    public function prompt($message)
    {
        return $message.'. Always check if I have other questions.';
    }

    /**
     * Define a custom chat history implementation
     */
    public function createChatHistory($name)
    {
        return new LarAgent\History\JsonChatHistory($name, ['folder' => __DIR__.'/../json_history']);
    }

    /**
     * Register tools programmatically
     */
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

    /**
     * Example of a tool defined as a method with optional and required parameters
     */
    #[Tool('Get the current weather in a given location')]
    public function weatherTool($location, $unit = 'celsius')
    {
        $temperature = 20;

        return "The weather in {$location} is {$temperature} degrees {$unit}";
    }

    /**
     * Example of using static method as tool
     */
    #[Tool('Get the current weather in New York', ['unit' => 'Unit of temperature'])]
    public static function weatherToolForNewYork(Unit $unit)
    {
        $temperature = 18;

        return "The weather in New York is {$temperature} degrees {$unit->value}";
    }
}

// Run the example
echo "🚀 Running LarAgent with Agent class approach...\n\n";

// Example 1: Basic question
echo "💬 User: What's the weather like in Boston and Los Angeles? I prefer fahrenheit\n";
echo '🤖 Response: ';
echo WeatherAgent::for('example_chat')->respond('What\'s the weather like in Boston and Los Angeles? I prefer fahrenheit');
echo "\n\n";

// Example 2: Using enum parameter
echo "💬 User: Thanks for the info. What about New York? I prefer celsius\n";
echo '🤖 Response: ';
echo WeatherAgent::for('example_chat')->respond('Thanks for the info. What about New York? I prefer celsius');
echo "\n\n";

// Example 3: Using user location tool
echo "💬 User: Where am I now?\n";
echo '🤖 Response: ';
echo WeatherAgent::for('example_chat')->message('Where am I now?')->respond();
echo "\n\n";

echo "✅ Example completed\n";
