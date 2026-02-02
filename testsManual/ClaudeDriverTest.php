<?php

use LarAgent\Agent;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Tests\TestCase;
use LarAgent\Tool;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class);

beforeEach(function () {

    $yourApiKey = include 'anthropic-api-key.php';

    config()->set('laragent.fallback_provider', 'claude');

    config()->set('laragent.providers.claude', [
        'label' => 'claude',
        'model' => 'claude-3-7-sonnet-latest',
        'api_key' => $yourApiKey,
        'driver' => ClaudeDriver::class,
        'default_truncation_threshold' => 200000,
        'default_max_completion_tokens' => 8192,
        'default_temperature' => 0.9,
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
class ClaudeTestAgent extends Agent
{
    protected $provider = 'claude';

    protected $model = 'claude-3-7-sonnet-latest';

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
class ToolTestAgent extends ClaudeTestAgent
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

    protected function afterToolExecution(\LarAgent\Core\Contracts\Tool $tool, \LarAgent\Core\Contracts\ToolCall $toolCall, &$result)
    {
        $this->saveToolResult = $result;
    }
}

// Test Agent using parallel tools
class ParallelToolTestAgent extends ClaudeTestAgent
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

    protected function afterToolExecution(\LarAgent\Core\Contracts\Tool $tool, \LarAgent\Core\Contracts\ToolCall $toolCall, &$result)
    {
        $this->toolCalls[] = [
            'tool' => $tool->getName(),
            'result' => $result,
        ];
    }
}

// To test error is thrown when structuredOutput is used with the Anthropic/Claude driver.
class StructuredOutputClaudeTestAgent extends ClaudeTestAgent
{
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

it('can send a message using respond', function () {
    $agent = ClaudeTestAgent::for('send_test');

    $response = $agent->respond('Say anything and end your response with "This is a test response"');

    expect($response)->toContain('This is a test response');
});

/**
 * This test verifies that structured output now works with the Anthropic/Claude driver.
 * Claude now supports JSON schema-based structured output via the output_config parameter.
 * Reference: https://docs.anthropic.com/en/docs/build-with-claude/structured-outputs
 */
it('supports structured output with response schema', function () {
    $agent = StructuredOutputClaudeTestAgent::for('structured_test');

    $response = $agent->respond('The Apple Watch is priced around $799.');

    // Response should be an array (JSON decoded)
    expect($response)->toBeArray()
        ->and($response)->toHaveKey('name')
        ->and($response)->toHaveKey('price')
        ->and($response['name'])->toBeString()
        ->and($response['price'])->toBeString()
        ->and(strtolower($response['name']))->toContain('watch')
        ->and($response['price'])->toContain('799');
});

it('supports structured output with raw schema', function () {
    // Use raw schema (not OpenAI-wrapped)
    $rawSchema = [
        'type' => 'object',
        'properties' => [
            'product_name' => ['type' => 'string', 'description' => 'Name of the product'],
            'product_price' => ['type' => 'number', 'description' => 'Price as a number'],
            'currency' => ['type' => 'string', 'description' => 'Currency code'],
        ],
        'required' => ['product_name', 'product_price', 'currency'],
    ];

    $agent = ClaudeTestAgent::for('raw_schema_test');
    $agent->responseSchema($rawSchema);

    $response = $agent->respond('Samsung Galaxy S24 costs $999 USD');

    // Response should be an array matching the schema
    expect($response)->toBeArray()
        ->and($response)->toHaveKey('product_name')
        ->and($response)->toHaveKey('product_price')
        ->and($response)->toHaveKey('currency')
        ->and(strtolower($response['product_name']))->toContain('samsung')
        ->and($response['product_price'])->toBeNumeric()
        ->and($response['currency'])->toBe('USD');
});

it('supports streaming with structured output', function () {
    $agent = StructuredOutputClaudeTestAgent::for('structured_stream_test');

    $stream = $agent->respondStreamed('Sony PlayStation 5 is priced at $499.');

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
    $content = $lastMessage->getContentAsString();

    // Content should be valid JSON
    $decoded = json_decode($content, true);
    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('name')
        ->and($decoded)->toHaveKey('price')
        ->and(strtolower($decoded['name']))->toContain('playstation')
        ->and($decoded['price'])->toContain('499');
});

it('can stream responses using respondStreamed', function () {
    $agent = ClaudeTestAgent::for('response_streamed_test');

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
    expect($lastMessage->getContentAsString())->toContain('This is a streaming response');
});

it('can stream responses using streamResponse in plain format', function () {
    $agent = ClaudeTestAgent::for('stream_response_test');

    // Get the response
    $response = $agent->streamResponse('Say any sentence and end your response with "This is a response from claude"', 'plain');

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
    expect($output)->toContain('This is a response from claude');
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

    expect(strtolower($response))->toContain('malaysia')->toContain('celsius');
});

it('can use multiple tools in parallel', function () {
    $agent = ParallelToolTestAgent::for('parallel_weather_test');

    $response = $agent->respond("What's the weather and temperature like in Kuala Lumpur and Tokyo?");

    // There should be 4 tool calls: 2 cities x 2 tools
    expect($agent->toolCalls)->toHaveCount(4);

    $toolNames = array_column($agent->toolCalls, 'tool');
    expect($toolNames)->toContain('get_temperature')
        ->toContain('get_weather_condition');

    expect(strtolower($response))->toContain('kuala lumpur')->toContain('tokyo')->toContain('celsius');
});

it('can use vision model with image url', function () {
    $agent = ClaudeTestAgent::for('vision_test');
    $agent->withImages([
        'https://blog.laragent.ai/content/images/2025/05/light.png',
        'https://blog.laragent.ai/content/images/size/w2000/2025/07/ChatGPT-Image-Jul-28--2025--11_01_48-AM.png',
    ]);

    $response = $agent->respond('What is the text in this image?');

    expect($response)->toContain('LarAGENT');
});
