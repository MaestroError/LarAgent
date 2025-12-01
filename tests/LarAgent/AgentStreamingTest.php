<?php

use LarAgent\Agent;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Message;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\ToolCall;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamedFakeLlmDriver extends FakeLlmDriver
{
    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = new DriverConfig, ?callable $callback = null): \Generator
    {
        $this->lastOverrideSettings = $overrideSettings instanceof DriverConfig 
            ? $overrideSettings->toArray() 
            : $overrideSettings;

        if (empty($this->mockResponses)) {
            throw new \Exception('No mock responses are defined.');
        }

        $mockResponse = array_shift($this->mockResponses);
        $finishReason = $mockResponse['finishReason'];
        $data = $mockResponse['responseData'];

        if ($finishReason === 'stop') {
            $msg = new StreamedAssistantMessage('');
            $msg->appendContent($data['content']);
            $msg->setComplete(true);

            if ($callback) {
                $callback($msg);
            }

            yield $msg;
        } elseif ($finishReason === 'tool_calls') {
            $toolCallId = '12345';
            $toolCalls[] = new ToolCall($toolCallId, $data['toolName'], $data['arguments']);
            $toolCallMessage = new ToolCallMessage(
                $toolCalls,
                $this->toolCallsToMessage($toolCalls),
                $data['metaData'] ?? []
            );

            if ($callback) {
                $callback($toolCallMessage);
            }

            yield $toolCallMessage;
        } else {
            throw new \Exception('Unexpected finish reason: '.$finishReason);
        }
    }
}

class StreamingTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = StreamedFakeLlmDriver::class;

    // Disable structured output by default for basic tests
    protected $responseSchema = null;

    public function instructions()
    {
        return 'You are a streaming test agent.';
    }

    public function prompt($message)
    {
        return $message.' Please respond appropriately.';
    }

    // Override the structuredOutput method to return null by default
    public function structuredOutput()
    {
        return null;
    }

    protected function onInitialize()
    {
        // Mock a streaming response with multiple chunks
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'This is a streaming response',
        ]);
    }
}

class StructuredOutputTestAgent extends StreamingTestAgent
{
    // Define the schema for structured output tests
    protected $responseSchema = [
        'name' => 'Profile',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'interests' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['name', 'age', 'interests'],
        ],
    ];

    // Override to return the schema
    public function structuredOutput()
    {
        return $this->responseSchema;
    }

    protected function onInitialize()
    {
        // Mock a structured output response
        $this->llmDriver->addMockResponse('stop', [
            'content' => json_encode([
                'name' => 'John Doe',
                'age' => 30,
                'interests' => ['coding', 'reading', 'hiking'],
            ]),
        ]);
    }
}

// Mock the Laravel response function if it doesn't exist
if (! function_exists('response')) {
    function response()
    {
        return new class
        {
            public function stream(callable $callback, int $status = 200, array $headers = [])
            {
                $response = new StreamedResponse($callback, $status, $headers);

                return $response;
            }
        };
    }
}

// Test the respondStreamed method
it('can stream responses using respondStreamed method', function () {
    $agent = new StreamingTestAgent('test_key');

    // Get the stream
    $stream = $agent->respondStreamed('Test message');

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

// Test the streaming with a callback
it('can stream responses with a callback', function () {
    $agent = new StreamingTestAgent('test_key');

    // Collect chunks in this array
    $receivedChunks = [];

    // Get the stream with a callback
    $stream = $agent->respondStreamed('Test message', function ($chunk) use (&$receivedChunks) {
        $receivedChunks[] = $chunk;
    });

    // Consume the stream
    foreach ($stream as $_) {
        // The callback handles the chunks
    }

    // Verify we received chunks via the callback
    expect($receivedChunks)->not->toBeEmpty();

    // Check the content of the last chunk
    $lastChunk = end($receivedChunks);
    expect($lastChunk->getContentAsString())->toContain('This is a streaming response');
});

// Test structured output streaming
it('can stream structured output responses', function () {
    $agent = new StructuredOutputTestAgent('test_key');

    // Get the stream
    $stream = $agent->respondStreamed('Test message');

    // Collect all messages from the stream
    $messages = [];
    foreach ($stream as $message) {
        $messages[] = $message;
    }

    // Verify we received messages
    expect($messages)->not->toBeEmpty();

    // The last message should be the structured output
    $lastMessage = end($messages);

    // If it's an array, it's the structured output
    if (is_array($lastMessage)) {
        expect($lastMessage)->toHaveKeys(['name', 'age', 'interests'])
            ->and($lastMessage['name'])->toBe('John Doe')
            ->and($lastMessage['age'])->toBe(30)
            ->and($lastMessage['interests'])->toBe(['coding', 'reading', 'hiking']);
    }
});

// Test the streamResponse method with plain format
it('can generate a stream response with plain format', function () {
    $agent = new StreamingTestAgent('test_key');

    // Get the response
    $response = $agent->streamResponse('Test message', 'plain');

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
    expect($output)->toContain('This is a streaming response');
});

// Test the streamResponse method with JSON format
it('can generate a stream response with JSON format', function () {
    $agent = new StreamingTestAgent('test_key');

    // Create a test to verify the content type is set correctly
    // We'll use runkit_function_redefine if available, otherwise skip
    if (! function_exists('runkit_function_redefine') && ! function_exists('uopz_set_return')) {
        // Instead of skipping, we'll just verify the match() logic works
        $contentType = match ('json') {
            'json' => 'application/json',
            'sse' => 'text/event-stream',
            default => 'text/plain',
        };

        expect($contentType)->toBe('application/json');
    }

    // Get the response
    $response = $agent->streamResponse('Test message', 'json');

    // Verify it's a StreamedResponse
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Verify the content type header
    expect($response->headers->get('Content-Type'))->toBe('application/json');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Body should include the streamed text
    expect($output)->toContain('This is a streaming response');
});

// Test the streamResponse method with SSE format
it('can generate a stream response with SSE format', function () {
    $agent = new StreamingTestAgent('test_key');

    // Create a test to verify the content type is set correctly
    // We'll use runkit_function_redefine if available, otherwise skip
    if (! function_exists('runkit_function_redefine') && ! function_exists('uopz_set_return')) {
        // Instead of skipping, we'll just verify the match() logic works
        $contentType = match ('sse') {
            'json' => 'application/json',
            'sse' => 'text/event-stream',
            default => 'text/plain',
        };

        expect($contentType)->toBe('text/event-stream');
    }

    // Get the response
    $response = $agent->streamResponse('Test message', 'sse');

    // Verify it's a StreamedResponse
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Verify the content type header
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream');

    // Capture the streamed output
    ob_start();
    ob_start();
    $response->sendContent();
    ob_get_clean(); // inner buffer flushed by response
    $output = ob_get_clean();

    // Output should include SSE events and content
    expect($output)->toContain('event: chunk')
        ->and($output)->toContain('event: complete')
        ->and($output)->toContain('This is a streaming response');
});
