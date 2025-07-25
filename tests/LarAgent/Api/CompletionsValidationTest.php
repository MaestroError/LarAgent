<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LarAgent\API\Completions;
use LarAgent\Agent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

class DummyAgent extends Agent
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
            'content' => 'dummy',
        ]);
    }
}

it('throws validation exception when messages field is missing', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
    ]);

    Completions::make($request, DummyAgent::class);
})->throws(ValidationException::class);

it('throws validation exception when audio is requested but not provided', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'modalities' => ['audio'],
    ]);

    Completions::make($request, DummyAgent::class);
})->throws(ValidationException::class);

it('validates a correct request', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'modalities' => ['text', 'audio'],
        'audio' => ['format' => 'mp3', 'voice' => 'nova'],
    ]);

    $result = Completions::make($request, DummyAgent::class);

    expect($result)->toHaveKey('choices')
        ->and($result['model'])->toBe('gpt-4o');
});

