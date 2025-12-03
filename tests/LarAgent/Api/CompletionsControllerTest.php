<?php

use Illuminate\Http\Request;
use LarAgent\Agent;
use LarAgent\API\Completions;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Tool;
use LarAgent\ToolCall;

class StreamDriver extends FakeLlmDriver
{
    public function sendMessageStreamed(MessageArray $messages, DriverConfig|array $overrideSettings = new DriverConfig, ?callable $callback = null): \Generator
    {
        $this->lastOverrideSettings = $overrideSettings instanceof DriverConfig 
            ? $overrideSettings->toArray() 
            : $overrideSettings;
        if (empty($this->mockResponses)) {
            throw new \Exception('No mock responses are defined.');
        }
        $mock = array_shift($this->mockResponses);
        $finish = $mock['finishReason'];
        $data = $mock['responseData'];
        if ($finish === 'stop') {
            $msg = new StreamedAssistantMessage('');
            $msg->appendContent($data['content']);
            $msg->setComplete(true);
            if ($callback) {
                $callback($msg);
            }
            yield $msg;
        } elseif ($finish === 'tool_calls') {
            $id = '1';
            $calls[] = new ToolCall($id, $data['toolName'], $data['arguments']);
            $message = new ToolCallMessage($calls, $this->toolCallsToMessage($calls));
            if ($callback) {
                $callback($message);
            }
            yield $message;
        }
    }
}

class BasicAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'basic';
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', ['content' => 'pong']);
    }
}

class StreamAgent extends BasicAgent
{
    protected $driver = StreamDriver::class;

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', ['content' => 'pong-stream']);
    }
}

class StructuredAgent extends BasicAgent
{
    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', ['content' => json_encode(['sentiment' => 'positive'])]);
    }
}

class StructuredStreamAgent extends StructuredAgent
{
    protected $driver = StreamDriver::class;
}

class ToolsAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'tools';
    }

    public function prompt($message)
    {
        return $message;
    }

    public function registerTools()
    {
        return [
            Tool::create('weatherTool', 'desc')
                ->addProperty('location', 'string', 'city')
                ->setRequired('location')
                ->setCallback(fn ($location) => 'The weather in '.$location.' is 20 degrees celsius'),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'weatherTool',
            'arguments' => json_encode(['location' => 'Paris']),
        ]);
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'The weather in Paris is 20 degrees celsius',
        ]);
    }
}

class ToolsStreamAgent extends ToolsAgent
{
    protected $driver = StreamDriver::class;

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'weatherTool',
            'arguments' => json_encode(['location' => 'Paris']),
        ]);
    }
}

class ToolsResultAgent extends BasicAgent
{
    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'The weather in New York is 20 degrees celsius',
        ]);
    }
}

it('handles a regular completion request', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Ping'],
        ],
    ]);

    $response = Completions::make($request, BasicAgent::class);

    expect($response['choices'][0]['message']['content'])->toBe('pong')
        ->and($response)->toHaveKeys(['id', 'model', 'choices']);
});

it('streams a regular completion request', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Ping'],
        ],
        'stream' => true,
    ]);

    $stream = Completions::make($request, StreamAgent::class);

    expect($stream)->toBeInstanceOf(Generator::class);

    $chunks = iterator_to_array($stream);
    $content = $chunks[array_key_last($chunks)]['choices'][0]['delta']['content'] ?? '';

    expect($content)->toBe('pong-stream');
});

it('handles structured output requests', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'response_format' => ['type' => 'json_object'],
    ]);

    $response = Completions::make($request, StructuredAgent::class);

    expect($response['choices'][0]['message']['content'])->toBeJson();
});

it('streams structured output', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'response_format' => ['type' => 'json_object'],
        'stream' => true,
    ]);

    $stream = Completions::make($request, StructuredStreamAgent::class);

    expect($stream)->toBeInstanceOf(Generator::class);
    $chunks = iterator_to_array($stream);
    $content = $chunks[array_key_last($chunks)]['choices'][0]['delta']['content'] ?? '';
    expect($chunks)->not->toBeEmpty();
    expect(json_decode($content, true))->toBeArray();
});

it('handles tool calls and returns final message', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => "What's the weather?"],
        ],
    ]);

    $response = Completions::make($request, ToolsAgent::class);

    expect($response['choices'][0]['message']['content'])->toContain('Paris');
});

it('streams tool call messages', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => "What's the weather?"],
        ],
        'stream' => true,
    ]);

    $stream = Completions::make($request, ToolsStreamAgent::class);

    expect($stream)->toBeInstanceOf(Generator::class);

    $first = null;
    foreach ($stream as $chunk) {
        $first = $chunk;
        break;
    }

    expect($first['choices'][0]['delta'])->toHaveKey('tool_calls');
});

it('processes tool results and completes the conversation', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Weather in New York?'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
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
                'tool_call_id' => 'call_1',
            ],
        ],
    ]);

    $response = Completions::make($request, ToolsResultAgent::class);

    expect($response['choices'][0]['message']['content'])->toContain('New York');
});
