<?php

use LarAgent\Agent;
use LarAgent\PseudoTool;
use LarAgent\Tests\Fakes\FakeLlmDriver;

class PseudoToolAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public function registerTools()
    {
        return [
            PseudoTool::create('pseudo_tool', 'desc')->setCallback(fn () => 'ok'),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('tool_calls', [
            'toolName' => 'pseudo_tool',
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

it('returns ToolCallMessage array when pseudo tool is executed', function () {
    $agent = PseudoToolAgent::for('test');
    $result = $agent->respond('hi');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('tool_calls')
        ->and($result['tool_calls'][0]['function']['name'])->toBe('pseudo_tool');
});
