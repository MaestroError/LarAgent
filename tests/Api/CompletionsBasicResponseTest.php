<?php

use Illuminate\Http\Request;
use LarAgent\API\Completions;
use LarAgent\Agent;
use LarAgent\Tests\Fakes\FakeLlmDriver;

class BasicDummyAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a dummy agent.';
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello! How can I assist you today?',
        ]);
    }
}

it('returns a basic completion response', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
    ]);

    $response = Completions::make($request, BasicDummyAgent::class);

    expect($response)->toHaveKeys(['id', 'object', 'created', 'model', 'choices'])
        ->and($response['choices'][0]['message']['content'])
        ->toBe('Hello! How can I assist you today?');
});
