<?php

use Illuminate\Http\Request;
use LarAgent\API\Completions;
use LarAgent\Agent;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\Fakes\FakeLlmDriver;
use LarAgent\ToolCall;

class ApiStreamedDriver extends FakeLlmDriver
{
    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        $this->setConfig($options);
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
            if ($callback) { $callback($msg); }
            yield $msg;
        } elseif ($finish === 'tool_calls') {
            $id = '1';
            $calls[] = new ToolCall($id, $data['toolName'], $data['arguments']);
            $message = new ToolCallMessage($calls, $this->toolCallsToMessage($calls));
            if ($callback) { $callback($message); }
            yield $message;
        }
    }
}

class StreamingApiAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = ApiStreamedDriver::class;

    public function instructions()
    {
        return 'stream';
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'streamed response',
        ]);
    }
}

it('streams OpenAI compatible chunks', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'stream' => true,
    ]);

    $stream = Completions::make($request, StreamingApiAgent::class);

    expect($stream)->toBeInstanceOf(Generator::class);

    $chunks = [];
    foreach ($stream as $chunk) {
        $chunks[] = $chunk;
    }

    expect($chunks)->not->toBeEmpty();
    $first = $chunks[0];
    expect($first)->toHaveKeys(['id', 'model', 'choices']);
});
