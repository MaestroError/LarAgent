<?php

use Illuminate\Http\Request;
use LarAgent\Agent;
use LarAgent\API\Completions;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

class MetadataDummyAgent extends Agent
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
            'content' => 'hello',
            'metaData' => [
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                    'total_tokens' => 2,
                ],
            ],
        ]);
    }
}

it('returns usage metadata in completion response', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
    ]);

    $response = Completions::make($request, MetadataDummyAgent::class);

    expect($response)->toHaveKeys(['id', 'model', 'usage'])
        ->and($response['usage'])->toBeArray();
});
