<?php

use Illuminate\Http\Request;
use LarAgent\API\Completions;
use LarAgent\Agent;
use LarAgent\PhantomTool;
use LarAgent\Tests\Fakes\FakeLlmDriver;

class ToolsApiAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public static array $registeredTools = [];

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'api_tool',
            'arguments' => '{}',
        ]);
    }

    public function instructions()
    {
        return 'dummy';
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function afterResponse($message)
    {
        self::$registeredTools = array_keys($this->llmDriver->getRegisteredTools());
    }
}

it('registers tools from request and returns OpenAI compatible tool call message', function () {
    ToolsApiAgent::$registeredTools = [];

    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'hi'],
        ],
        'tools' => [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'api_tool',
                    'description' => 'desc',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'foo' => ['type' => 'string'],
                        ],
                        'required' => ['foo'],
                    ],
                ],
            ],
        ],
    ]);

    $response = Completions::make($request, ToolsApiAgent::class);

    expect(ToolsApiAgent::$registeredTools)->toContain('api_tool')
        ->and($response['choices'][0]['message']['tool_calls'][0]['function']['name'])->toBe('api_tool')
        ->and($response['choices'][0]['finish_reason'])->toBe('tool_calls');
});
