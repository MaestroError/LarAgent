<?php

use Illuminate\Support\Facades\Route;
use LarAgent\Agent;
use LarAgent\API\Completion\Controllers\MultiAgentController;
use LarAgent\API\Completion\Controllers\SingleAgentController;
use LarAgent\Attributes\Tool;
use LarAgent\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class);

/**
 * To run this manual test you will need ollama installed
 * and running http://localhost:11434/v1 as usual
 * Also, pulled the "llama3.2:3b" & "granite3.2-vision:latest" models from ollama
 * Or update them with your models
 */
beforeEach(function () {

    config()->set('app.debug', true);

    config()->set('laragent.fallback_provider', 'ollama');
    // Server configs (Exposed agents via API)
    config()->set('laragent.providers.ollama', [
        'label' => 'ollama-local',
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiCompatible::class,
        'api_key' => 'ollama', // Can be any string for Ollama
        'api_url' => 'http://localhost:11434/v1',
        'model' => 'llama3.2:3b',
        'default_context_window' => 50000,
        'default_max_completion_tokens' => 100,
        'default_temperature' => 1,
    ]);

    // Client configs
    config()->set('laragent.providers.oneagent', [
        'label' => 'oneagent-local',
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiCompatible::class,
        'api_key' => 'oneagent', // Can be any string for Ollama
        'api_url' => '/api/one-agent',
        'default_context_window' => 50000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
    ]);
    config()->set('laragent.providers.multiagent', [
        'label' => 'multiagent-local',
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiCompatible::class,
        'api_key' => 'multiagent', // Can be any string for Ollama
        'api_url' => '/api/multi-agent',
        'default_context_window' => 50000,
        'default_max_completion_tokens' => 1000,
        'default_temperature' => 1,
    ]);

    Route::middleware('api')->prefix('api')->group(function () {
        Route::post('/one-agent/chat/completions', [OneAgentController::class, 'completion']);
        Route::get('/one-agent/models', [OneAgentController::class, 'models']);
        Route::post('/multi-agent/chat/completions', [ManyAgentController::class, 'completion']);
        Route::get('/multi-agent/models', [ManyAgentController::class, 'models']);
    });
});

class ApiAgentControllerTest extends Agent
{
    protected $model = 'granite3.2-vision:latest';

    protected $history = 'in_memory';

    protected $provider = 'ollama';

    protected $tools = [];

    public function instructions()
    {
        return 'You are a helpful assistant';
    }

    public function prompt($message)
    {
        return $message;
    }

    #[Tool('Get the current weather in a given location')]
    public function weatherTool($location, $unit = 'celsius')
    {
        // echo "// Wheather tool called for $location // \n\n";

        return 'The weather in '.$location.' is '.'20'.' degrees '.$unit;
    }
}

class PirateAgentControllerTest extends Agent
{
    protected $model = 'llama3.2:3b';

    protected $history = 'in_memory';

    protected $provider = 'ollama';

    protected $tools = [];

    public function instructions()
    {
        return 'You are a friendly and fun pirate';
    }

    public function prompt($message)
    {
        return $message;
    }
}

class OneAgentController extends SingleAgentController
{
    protected ?string $agentClass = ApiAgentControllerTest::class;

    protected ?array $models = [
        'granite3.2-vision:latest',
        'llama3.2:3b',
    ];

    /**
     * Set the session id for the agent
     * Without this method, the session id will be random string per each request
     * Since clients manage the chat history, this method is not needed
     * if you don't store the chats (using in_memory ChatHistory type)
     *
     * @return string
     */
    protected function setSessionId()
    {
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        return 'OpenWebUi-LarAgent';
    }
}

class ManyAgentController extends MultiAgentController
{
    protected ?array $agents = [
        ApiAgentControllerTest::class,
        PirateAgentControllerTest::class,
    ];

    protected ?array $models = [
        'ApiAgentControllerTest/granite3.2-vision:latest',
        'ApiAgentControllerTest/llama3.2:3b',
        'PirateAgentControllerTest',
    ];
}

function processStreamedResponse($response)
{
    $lines = explode("\n", $response);
    $dataLines = array_filter($lines, fn ($line) => str_starts_with($line, 'data: '));
    $chunks = array_map(function ($line) {
        $json = trim(substr($line, 6));

        return json_decode($json, true);
    }, $dataLines);
    $chunks = array_filter($chunks);

    return collect($chunks);
}

function getContentFromChunks($chunks)
{
    return $chunks
        ->pluck('choices')
        ->flatten(1)
        ->pluck('delta.content')
        ->filter()
        ->join('');
}

it('can process completion', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'granite3.2-vision:latest',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('granite3.2-vision:latest');
    expect($response['usage'])->toBeArray();
    expect($response['usage']['total_tokens'])->toBeGreaterThan(0);
});

it('returns error when model is not found', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'gpt-4.1-nano',
        'messages' => [
            [
                'role' => 'developer',
                'content' => 'You are a helpful assistant.',
            ],
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
        ],
        // "stream" => true
    ])->json();

    expect($response)->toBeArray();
    expect($response['error'])->toBe('Invalid model name');
});

it('can process completion with stream', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'llama3.2:3b',
        'messages' => [
            [
                'role' => 'developer',
                'content' => 'You are a helpful assistant.',
            ],
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
        ],
        'stream' => true,
    ]);

    // Verify it's a StreamedResponse
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    // Check headers directly from the response object
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream; charset=UTF-8');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Receive chunks from the response
    $chunks = processStreamedResponse($output);

    // Assert structure of a sample chunk
    expect($chunks->first())->toHaveKeys(['id', 'object', 'choices']);
    expect($chunks->first()['choices'][0])->toHaveKeys(['delta', 'finish_reason']);
    expect($chunks->first()['model'])->toBe('llama3.2:3b');
    expect($chunks->last()['usage'])->toBeArray();
    expect($chunks->last()['usage']['total_tokens'])->toBeGreaterThan(0);

    expect(getContentFromChunks($chunks))->toBeString();
});

it('can process structured completion', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'llama3.2:3b',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Analyze the sentiment of: I love this product!',
            ],
        ],
        'response_format' => [
            'type' => 'json_object',
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['choices'][0]['message']['content'])->toBeJson();
    expect($response['model'])->toBe('llama3.2:3b');
    expect($response['usage'])->toBeArray();
    expect($response['usage']['total_tokens'])->toBeGreaterThan(0);

    // Validate JSON structure
    $content = json_decode($response['choices'][0]['message']['content'], true);
    expect($content)->toBeArray();
});

it('can process structured completion with streaming', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'llama3.2:3b',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Analyze the sentiment of: I love this product!',
            ],
        ],
        'response_format' => [
            'type' => 'json_object',
        ],
        'stream' => true,
    ]);

    // Verify it's a StreamedResponse
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    // Check headers
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream; charset=UTF-8');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Receive chunks from the response
    $chunks = processStreamedResponse($output);

    // Assert structure
    expect($chunks->first())->toHaveKeys(['id', 'object', 'choices']);
    expect($chunks->first()['choices'][0])->toHaveKeys(['delta', 'finish_reason']);
    expect($chunks->first()['model'])->toBe('llama3.2:3b');
    expect($chunks->last()['usage'])->toBeArray();

    // Combine content and validate JSON
    $content = getContentFromChunks($chunks);
    expect($content)->toBeString();
    expect(json_decode($content))->not->toBeNull();
});

it('can process completion with tools (Inner)', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'llama3.2:3b',
        'messages' => [
            [
                'role' => 'user',
                'content' => "What's the weather in Paris?",
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('llama3.2:3b');
    expect($response['usage'])->toBeArray();

    // Verify tool call
    expect($response['choices'][0]['message']['content'])->toBeString();
    expect($response['choices'][0]['message']['content'])->toContain('20');
});

it('can process completion with tools and streaming', function () {
    $route = config('laragent.providers.multiagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'ApiAgentControllerTest/llama3.2:3b',
        'messages' => [
            [
                'role' => 'user',
                'content' => "What's the weather in Paris?",
            ],
        ],
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'weatherTool',
                    'description' => 'Get the current weather in a given location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => [
                                'type' => 'string',
                                'description' => 'The city and state, e.g. San Francisco, CA',
                            ],
                            'unit' => [
                                'type' => 'string',
                                'enum' => ['celsius', 'fahrenheit'],
                                'description' => 'The temperature unit to use',
                            ],
                        ],
                        'required' => ['location'],
                    ],
                ],
            ],
        ],
        'stream' => true,
    ]);

    // Verify it's a StreamedResponse
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    // Check headers
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream; charset=UTF-8');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean();
    $output = ob_get_clean();

    // Process response
    $chunks = processStreamedResponse($output);

    // Basic validation
    expect($chunks)->not->toBeEmpty();
    expect($chunks->first())->toHaveKeys(['id', 'object', 'choices']);
    expect($chunks->first()['model'])->toBe('llama3.2:3b');

    // Verify tool call chunks exist
    $hasToolCall = $chunks->reduce(function ($carry, $chunk) {
        if (isset($chunk['choices'][0]['delta']['tool_calls'])) {
            return true;
        }

        return $carry;
    }, false);

    expect($hasToolCall)->toBeTrue();
});

it('can process completion with tools (Phantom)', function () {
    $route = config('laragent.providers.multiagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'PirateAgentControllerTest',
        'messages' => [
            [
                'role' => 'user',
                'content' => "What's the weather in Paris?",
            ],
        ],
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'weatherTool',
                    'description' => 'Get the current weather in a given location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => [
                                'type' => 'string',
                                'description' => 'The city and state, e.g. San Francisco, CA',
                            ],
                            'unit' => [
                                'type' => 'string',
                                'enum' => ['celsius', 'fahrenheit'],
                                'description' => 'The temperature unit to use',
                            ],
                        ],
                        'required' => ['location'],
                    ],
                ],
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    // dd($response);
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('llama3.2:3b');
    expect($response['usage'])->toBeArray();

    // Verify tool call
    expect($response['choices'][0]['message'])->toHaveKey('tool_calls');
    expect($response['choices'][0]['message']['tool_calls'])->toBeArray();
    expect($response['choices'][0]['message']['tool_calls'][0]['function']['name'])->toBe('weatherTool');

    // Check function arguments
    $args = json_decode($response['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true);
    expect($args)->toHaveKey('location');
    expect($args['location'])->toBeString();
});

it('can process completion via multiagent controller using "AgentName/ModelName"', function () {
    $route = config('laragent.providers.multiagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'ApiAgentControllerTest/granite3.2-vision:latest',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('granite3.2-vision:latest');
    expect($response['usage'])->toBeArray();
    expect($response['usage']['total_tokens'])->toBeGreaterThan(0);
});

it('can process completion via multiagent controller using "AgentName"', function () {
    $route = config('laragent.providers.multiagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'ApiAgentControllerTest',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('granite3.2-vision:latest');
    expect($response['usage'])->toBeArray();
    expect($response['usage']['total_tokens'])->toBeGreaterThan(0);
});

it('can process tool results', function () {
    $route = config('laragent.providers.oneagent.api_url').'/chat/completions';
    $response = $this->postJson($route, [
        'model' => 'llama3.2:3b',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'What is the weather like in New York today?',
            ],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_W5hSnhnG8fRiaFQNfqZglzEb',
                        'type' => 'function',
                        'function' => [
                            'name' => 'weatherTool',
                            'arguments' => '{"location":"New York"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'content' => '{"location":"New York","weatherTool":"The weather in New York is 20 degrees celsius"}',
                'tool_call_id' => 'call_W5hSnhnG8fRiaFQNfqZglzEb',
            ],
        ],
    ])->json();

    expect($response)->toBeArray();
    expect($response['choices'][0]['message']['role'])->toBe('assistant');
    expect($response['model'])->toBe('llama3.2:3b');
    expect($response['usage'])->toBeArray();

    // Verify the response contains information about the weather in New York
    expect($response['choices'][0]['message']['content'])->toContain('New York');
    expect($response['choices'][0]['message']['content'])->toContain('20');
});
