<?php

use LarAgent\Agent;
use LarAgent\Drivers\OpenAi\OpenRouter;
use LarAgent\Tests\TestCase;
use LarAgent\Tool;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class);

beforeEach(function () {
    $yourApiKey = include 'openrouter-api-key.php';

    config()->set('laragent.fallback_provider', 'openrouter');

    config()->set('laragent.providers.openrouter', [
        'label' => 'openrouter',
        'model' => 'deepseek/deepseek-chat-v3.1:free', // Using a free model for testing
        'api_key' => $yourApiKey,
        'driver' => OpenRouter::class,
        'default_context_window' => 200000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 0.9,
        'referer' => 'https://laragent.ai/',
        'title' => 'LarAgent',
    ]);
});

// WeatherTool class
class WeatherTool extends Tool
{
    protected string $name = 'get_current_weather';

    protected string $description = 'Get the current weather in a given country.Respond using the word "celsius" or "fahrenheit" instead of symbols like °C or °F.';

    protected array $properties = [
        'location' => [
            'type' => 'string',
            'description' => 'The country, e.g Malaysia, Singapore',
        ],
        'unit' => [
            'type' => 'string',
            'description' => 'The unit of temperature',
            'enum' => ['celsius', 'fahrenheit'],
        ],
    ];

    protected array $required = ['location'];

    protected array $metaData = ['sent_at' => '2025-07-01'];

    public function execute(array $input): mixed
    {
        $location = $input['location'] ?? 'unknown location';
        $unit = $input['unit'] ?? 'celsius';

        if (strtolower($location) == 'malaysia') {
            $temperature = '32';
        } else {
            $temperature = '33';
        }

        return [
            'location' => $location,
            'unit' => $unit,
            'temperature' => $temperature,
        ];
    }
}

// TemperatureTool for parallelToolCalls test
class TemperatureTool extends Tool
{
    protected string $name = 'get_temperature';

    protected string $description = 'Get the temperature for a given city';

    protected array $properties = [
        'location' => [
            'type' => 'string',
            'description' => 'The name of the city',
        ],
    ];

    protected array $required = ['location'];

    protected array $metaData = ['sent_at' => '2025-07-01'];

    public function execute(array $input): mixed
    {
        $temperatures = [
            'Kuala Lumpur' => '32 celsius',
            'Tokyo' => '26 celsius',
        ];

        return $temperatures[$input['location']] ?? 'Temperature data not available';
    }
}

// WeatherConditionTool for parallelToolCalls test
class WeatherConditionTool extends Tool
{
    protected string $name = 'get_weather_condition';

    protected string $description = 'Get the weather condition for a given city';

    protected array $properties = [
        'location' => [
            'type' => 'string',
            'description' => 'The name of the city',
        ],
    ];

    protected array $required = ['location'];

    protected array $metaData = ['sent_at' => '2025-07-01'];

    public function execute(array $input): mixed
    {
        $conditions = [
            'Kuala Lumpur' => 'Sunny',
            'Tokyo' => 'Rainy',
        ];

        return $conditions[$input['location']] ?? 'Weather condition data not available';
    }
}

// Test Agent
class OpenRouterTestAgent extends Agent
{
    protected $provider = 'openrouter';

    protected $history = 'in_memory';

    public function instructions()
    {
        return 'You are a helpful assistant';
    }

    public function prompt($message)
    {
        return $message.' Please respond and follow instruction appropriately.';
    }
}

// Test Agent using WeatherTool
class ToolTestAgent extends OpenRouterTestAgent
{
    public $saveToolResult = null;

    public function instructions()
    {
        return <<<'EOT'
        You are a weather assistant. Always use the available tools to retrieve weather data.
        For any user request, do the following:
        - Call the tool to get temperature and weather for the location.
        - Respond only using the tool result, especially the summary field.
        - Do not include any extra notes, disclaimers, or general explanations.
        EOT;
    }

    public function prompt($message)
    {
        return 'Use the tools to complete this request. '.$message;
    }

    public function registerTools()
    {
        return [
            new WeatherTool,
        ];
    }

    protected function afterToolExecution($tool, &$result)
    {
        $this->saveToolResult = $result;
    }
}

// Test Agent using parallel tools
class ParallelToolTestAgent extends OpenRouterTestAgent
{
    public $toolCalls = [];

    public function instructions()
    {
        return <<<'EOT'
        You are a weather assistant. Always use the available tools to fetch temperature and weather condition.

        Your task:
        - Call tools to get temperature and condition for each city
        - Then reply with exactly one sentence per city in the format:
        "{City} is currently {condition} with a temperature of {temperature}."

        Do not include disclaimers, apologies, or additional commentary.
        EOT;
    }

    public function prompt($message)
    {
        return 'Use the tools to complete this request. '.$message;
    }

    public function registerTools()
    {
        return [
            new TemperatureTool,
            new WeatherConditionTool,
        ];
    }

    protected function afterToolExecution($tool, &$result)
    {
        $this->toolCalls[] = [
            'tool' => $tool->getName(),
            'result' => $result,
        ];
    }
}

// Streaming Tests Only

it('can stream responses using respondStreamed', function () {
    $agent = OpenRouterTestAgent::for('response_streamed_test');

    // Get the stream
    $stream = $agent->respondStreamed('Say anything and end your response with "This is a streaming response"');

    // Verify the stream is a Generator
    expect($stream)->toBeInstanceOf(\Generator::class);

    // Collect all messages from the stream
    $messages = [];
    foreach ($stream as $message) {
        $messages[] = $message;
    }

    // Verify we received messages
    expect($messages)->not->toBeEmpty();

    // Check the content of the last message
    $lastMessage = end($messages);
    expect($lastMessage->getContent() ?? $lastMessage)->toContain('This is a streaming response');
});

it('can stream responses using streamResponse in plain format', function () {
    $agent = OpenRouterTestAgent::for('stream_response_test');

    // Get the response
    $response = $agent->streamResponse('Say anything and end your response with "This is a streaming response"', 'plain');

    // Verify it's a StreamedResponse
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Check headers directly from the response object
    expect($response->headers->get('Content-Type'))->toBe('text/plain');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Ensure the body contains the expected text
    expect($output)->toContain('This is a streaming response');
});

it('can stream with tools use', function () {
    $agent = ToolTestAgent::for('stream_response_test');

    // Get the response
    $response = $agent->streamResponse('What is the current weather in Malaysia in celsius?', 'plain');

    // Verify it's a StreamedResponse
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Check headers directly from the response object
    expect($response->headers->get('Content-Type'))->toBe('text/plain');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Ensure the body contains the expected text
    expect(strtolower($output))->toContain('malaysia')->toContain('celsius');
});

it('can stream with multiple tools use', function () {
    $agent = ParallelToolTestAgent::for('parallel_stream_response_test');

    // Get the response
    $response = $agent->streamResponse("What's the weather and temperature like in Kuala Lumpur and Tokyo?", 'plain');

    // Verify it's a StreamedResponse
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Check headers directly from the response object
    expect($response->headers->get('Content-Type'))->toBe('text/plain');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Ensure the body contains the expected text
    expect(strtolower($output))->toContain('kuala lumpur')->toContain('tokyo')->toContain('celsius');
});
