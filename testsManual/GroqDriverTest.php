<?php

use LarAgent\Agent;
use LarAgent\Drivers\Groq\GroqDriver;
use LarAgent\Tests\TestCase;
use LarAgent\Tool;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class);

/**
 * To run this manual test, you will need to add your Groq API key to the groq-api-key.php file
 * just `return 'your-api-key';`
 */
beforeEach(function () {
    // get api key from Groq https://console.groq.com/keys
    $yourApiKey = include 'groq-api-key.php';

    config()->set('laragent.fallback_provider', 'groq');

    config()->set('laragent.providers.groq', [
        'label' => 'groq',
        'model' => 'openai/gpt-oss-120b',
        'driver' => GroqDriver::class,
        'api_key' => $yourApiKey,
        'default_context_window' => 131072,
        'default_max_completion_tokens' => 65536,
        'default_temperature' => 1,
    ]);
});

// WeatherTool class
class WeatherTool extends Tool
{
    protected string $name = 'get_current_weather';

    protected string $description = 'Get the current weather in a given country';

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
        $temperature = '32';

        return [
            'location' => $location,
            'unit' => $unit,
            'temperature' => $temperature,
            'summary' => "The weather in {$location} is {$temperature} degrees {$unit}.",
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

// Groq Test Agent
class GroqTestAgent extends Agent
{
    protected $provider = 'groq';

    protected $model = 'openai/gpt-oss-120b';

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

// Groq Test Agent using WeatherTool
class ToolTestAgent extends GroqTestAgent
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

    protected function afterToolExecution($tool, $toolCall, &$result)
    {
        $this->saveToolResult = $result;
    }
}

// Groq Test Agent using parallel tools
class ParallelToolTestAgent extends GroqTestAgent
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

    protected function afterToolExecution($tool, $toolCall, &$result)
    {
        $this->toolCalls[] = [
            'tool' => $tool->getName(),
            'result' => $result,
        ];
    }
}

// Groq Test Agent with strutured output(json_schema)
class StructuredOutputGroqTestAgent extends GroqTestAgent
{
    // Only certain model support json_schema
    // https://console.groq.com/docs/structured-outputs#supported-models
    protected $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

    protected $maxCompletionTokens = 8192;

    public function instructions()
    {
        return 'Extract structured product data (name and price) from the text.';
    }

    public function prompt($message)
    {
        return "Here is a product description: {$message}";
    }

    // Define the schema for structured output tests
    protected $responseSchema = [
        'name' => 'get_price',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name of the product'],
                'price' => ['type' => 'string', 'description' => 'Price of the product with currency'],
            ],
            'required' => ['name', 'price'],
        ],
    ];

    // Override to return the schema
    public function structuredOutput()
    {
        return $this->responseSchema;
    }
}

// Groq Test Agent with strutured output(json_object)
class SimpleStructuredOutputGroqTestAgent extends GroqTestAgent
{
    public function instructions()
    {
        return 'Extract product data (name of the product and price of the product with currency symbol) from the user message. Provide your output in json format with only the keys: name and price.';
    }

    public function prompt($message)
    {
        return "Here is a product description: {$message}";
    }

    // Enables json_object structured output
    public function structuredOutput()
    {
        return [
            'type' => 'json_object',
        ];
    }
}

// Groq Test Agent with vision
class VisionTestAgent extends GroqTestAgent
{
    // Only certain model support vision
    // https://console.groq.com/docs/vision
    protected $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

    protected $maxCompletionTokens = 8192;
}

it('can send a message using respond', function () {
    $agent = GroqTestAgent::for('send_test');

    $response = $agent->respond('Say anything and end your response with "This is a test response"');

    expect($response)->toContain('This is a test response');
});

it('can return structured output(json_schema)', function () {
    $agent = StructuredOutputGroqTestAgent::for('structured_test');

    $response = $agent->respond('The Apple Watch is priced around $799.');

    expect($response)
        ->toBeArray()
        ->and($response)->toHaveKeys(['name', 'price'])
        ->and($response['name'])->toContain('Apple Watch')
        ->and($response['price'])->toContain('$799');
});

it('can return structured output(json_object)', function () {
    $agent = SimpleStructuredOutputGroqTestAgent::for('json_test');

    $response = $agent->respond('The Apple Watch is priced around $799.');

    expect($response)
        ->toBeArray()
        ->and($response)->toHaveKeys(['name', 'price'])
        ->and($response['name'])->toContain('Apple Watch')
        ->and($response['price'])->toContain('$799');
});

it('can stream responses using respondStreamed', function () {
    $agent = GroqTestAgent::for('response_streamed_test');

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

    expect($lastMessage->getContentAsString() ?? $lastMessage)->toContain('This is a streaming response');
});

it('can stream responses using streamResponse in plain format', function () {
    $agent = GroqTestAgent::for('stream_response_test');

    // Get the response
    $response = $agent->streamResponse('Say anything and end your response with "This is a streaming response', 'plain');

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

it('can use tool', function () {
    $agent = ToolTestAgent::for('tool_test');

    $response = $agent->respond('What is the current weather in Malaysia in celsius?');
    // print($response);
    expect(strtolower($response))->toContain('malaysia')->toContain('celsius')
        ->and(strtolower($agent->saveToolResult['summary']))->toContain('malaysia')->toContain('celsius');
});

it('can user multiple tools in parallel', function () {
    $agent = ParallelToolTestAgent::for('parallel_weather_test');

    $response = $agent->respond("What's the weather and temperature like in Kuala Lumpur and Tokyo?");
    // print($response);
    // There should be 4 tool calls: 2 cities x 2 tools
    expect($agent->toolCalls)->toHaveCount(4);

    $toolNames = array_column($agent->toolCalls, 'tool');
    expect($toolNames)->toContain('get_temperature')
        ->toContain('get_weather_condition');

    expect($response)->toContain('Kuala Lumpur')->toContain('Tokyo');
});

it('can use vision model with image url', function () {
    $agent = VisionTestAgent::for('vision_test');
    $agent->withImages([
        'https://blog.laragent.ai/content/images/2025/05/light.png',
    ]);

    $response = $agent->respond('What is in this image?');

    expect($response)->toContain('LarAGENT');
});
