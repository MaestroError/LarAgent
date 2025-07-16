<?php

use LarAgent\Agent;
use LarAgent\PhantomTool;
use LarAgent\Tests\Fakes\FakeLlmDriver;

class PhantomToolAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public function registerTools()
    {
        return [
            PhantomTool::create('phantom_tool', 'desc')->setCallback(fn () => 'ok'),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'phantom_tool',
            'arguments' => '{}',
        ]);
    }

    public function instructions()
    {
        return 'test';
    }

    public function prompt($message)
    {
        return $message;
    }
}

it('returns ToolCallMessage array when phantom tool is executed', function () {
    $agent = PhantomToolAgent::for('test');
    $result = $agent->respond('hi');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('tool_calls')
        ->and($result['tool_calls'][0]['function']['name'])->toBe('phantom_tool');
});
