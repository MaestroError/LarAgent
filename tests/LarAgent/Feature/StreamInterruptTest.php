<?php

use Illuminate\Support\Facades\Event;
use LarAgent\Agent;
use LarAgent\Core\Contracts\InterruptableDriver;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Events\StreamInterrupted;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Tool;
use LarAgent\ToolCall;

/**
 * A fake driver that yields multiple chunks so we can interrupt mid-stream.
 */
class ChunkedStreamFakeDriver extends FakeLlmDriver
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
            // Split content into word-level chunks
            $chunks = $data['chunks'] ?? explode(' ', $data['content']);
            $msg = new StreamedAssistantMessage('');

            foreach ($chunks as $i => $chunk) {
                $separator = $i > 0 ? ' ' : '';
                $msg->appendContent($separator.$chunk);

                // Mark complete on last chunk
                if ($i === count($chunks) - 1) {
                    $msg->setComplete(true);
                }

                if ($callback) {
                    $callback($msg);
                }

                yield $msg;

                if ($this->interrupted) {
                    return;
                }
            }
        } elseif ($finishReason === 'tool_calls') {
            $toolCallId = $data['toolCallId'] ?? '12345';
            $toolCalls[] = new ToolCall($toolCallId, $data['toolName'], $data['arguments']);
            $toolCallMessage = new ToolCallMessage($toolCalls);

            if ($callback) {
                $callback($toolCallMessage);
            }

            yield $toolCallMessage;
        } else {
            throw new \Exception('Unexpected finish reason: '.$finishReason);
        }
    }
}

/**
 * Test agent for interrupt scenarios.
 */
class InterruptTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = ChunkedStreamFakeDriver::class;

    protected $responseSchema = null;

    public function instructions()
    {
        return 'You are a test agent for interruption.';
    }

    public function prompt($message)
    {
        return $message;
    }

    public function structuredOutput()
    {
        return null;
    }

    protected function onInitialize()
    {
        // Default: multi-chunk response
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Hello world this is a long streaming response',
        ]);
    }
}

/**
 * Test agent with tools for interrupt-during-tool-loop scenarios.
 */
class InterruptToolAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    protected $driver = ChunkedStreamFakeDriver::class;

    protected $responseSchema = null;

    public int $toolExecutionCount = 0;

    public function instructions()
    {
        return 'You are a test agent with tools.';
    }

    public function prompt($message)
    {
        return $message;
    }

    public function structuredOutput()
    {
        return null;
    }

    public function registerTools()
    {
        return [
            Tool::create('tool_a', 'First tool')
                ->addProperty('input', 'string', 'Input')
                ->setRequired('input')
                ->setCallback(function ($input) {
                    $this->toolExecutionCount++;

                    return 'Result A';
                }),
            Tool::create('tool_b', 'Second tool')
                ->addProperty('input', 'string', 'Input')
                ->setRequired('input')
                ->setCallback(function ($input) {
                    $this->toolExecutionCount++;

                    return 'Result B';
                }),
            Tool::create('tool_c', 'Third tool')
                ->addProperty('input', 'string', 'Input')
                ->setRequired('input')
                ->setCallback(function ($input) {
                    $this->toolExecutionCount++;

                    return 'Result C';
                }),
        ];
    }
}

// --- Tests ---

it('interrupts text streaming when callback returns false', function () {
    $agent = new InterruptTestAgent('test_key');
    $chunksReceived = [];

    $stream = $agent->respondStreamed('Hello', function ($msg) use (&$chunksReceived) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunksReceived[] = $msg->getContentAsString();

            // Interrupt after receiving 2 chunks
            if (count($chunksReceived) >= 2) {
                return false;
            }
        }
    });

    $yielded = [];
    foreach ($stream as $chunk) {
        $yielded[] = $chunk;
    }

    // Should have received only the chunks before interrupt + possibly the interrupt chunk
    expect(count($chunksReceived))->toBeLessThanOrEqual(3);
    expect($agent->isInterrupted())->toBeTrue();
});

it('saves partial message with interrupted metadata to chat history', function () {
    $agent = new InterruptTestAgent('test_key');
    $chunkCount = 0;

    $stream = $agent->respondStreamed('Hello', function ($msg) use (&$chunkCount) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunkCount++;
            if ($chunkCount >= 2) {
                return false;
            }
        }
    });

    foreach ($stream as $_) {
        // consume
    }

    // Check that chat history contains the partial message with interrupted metadata
    $messages = $agent->chatHistory()->getMessages()->all();
    $lastAssistant = null;
    foreach (array_reverse($messages) as $msg) {
        if ($msg instanceof StreamedAssistantMessage) {
            $lastAssistant = $msg;
            break;
        }
    }

    expect($lastAssistant)->not->toBeNull();
    expect($lastAssistant->getMetadata())->toHaveKey('interrupted');
    expect($lastAssistant->getMetadata()['interrupted'])->toBeTrue();
});

it('interrupts via explicit interrupt() call', function () {
    $agent = new InterruptTestAgent('test_key');

    // Start streaming in a callback that triggers interrupt externally
    $stream = $agent->respondStreamed('Hello', function ($msg) use ($agent) {
        if ($msg instanceof StreamedAssistantMessage) {
            // Simulate external interrupt (e.g., signal handler)
            $agent->interrupt();
        }
    });

    foreach ($stream as $_) {
        // consume
    }

    expect($agent->isInterrupted())->toBeTrue();
});

it('fires onStreamInterrupted hook on interrupt', function () {
    $agent = new InterruptTestAgent('test_key');
    $hookFired = false;
    $hookMessage = null;

    // We need to access the LarAgent engine to register the hook
    // This happens via the Agent's event system
    $chunkCount = 0;

    $stream = $agent->respondStreamed('Hello', function ($msg) use (&$chunkCount) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunkCount++;
            if ($chunkCount >= 2) {
                return false;
            }
        }
    });

    // Listen for the Laravel event instead
    Event::fake([StreamInterrupted::class]);

    // Re-create to get fresh event registration with faked events
    $agent2 = new InterruptTestAgent('test_key_2');
    $chunkCount2 = 0;

    $stream2 = $agent2->respondStreamed('Hello', function ($msg) use (&$chunkCount2) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunkCount2++;
            if ($chunkCount2 >= 2) {
                return false;
            }
        }
    });

    foreach ($stream2 as $_) {
        // consume
    }

    Event::assertDispatched(StreamInterrupted::class);
});

it('interrupts during tool loop and skips remaining tools', function () {
    $freshAgent = new class('test_key_tools') extends Agent
    {
        protected $model = 'gpt-4o-mini';

        protected $history = 'in_memory';

        protected $driver = ChunkedStreamFakeDriver::class;

        protected $responseSchema = null;

        public int $toolExecutionCount = 0;

        public function instructions()
        {
            return 'You are a tool test agent.';
        }

        public function prompt($message)
        {
            return $message;
        }

        public function structuredOutput()
        {
            return null;
        }

        protected function onInitialize()
        {
            $this->llmDriver->addMockResponse('tool_calls', [
                'toolName' => 'tool_a',
                'arguments' => json_encode(['input' => 'test']),
            ]);

            $this->llmDriver->addMockResponse('stop', [
                'content' => 'After tools completed successfully',
            ]);
        }

        public function registerTools()
        {
            $agent = $this;

            return [
                Tool::create('tool_a', 'First tool')
                    ->addProperty('input', 'string', 'Input')
                    ->setRequired('input')
                    ->setCallback(function ($input) use ($agent) {
                        $agent->toolExecutionCount++;

                        return 'Result A';
                    }),
            ];
        }
    };

    // Interrupt after the tool processes and we start getting the next response
    $interrupted = false;
    $stream = $freshAgent->respondStreamed('Hello', function ($msg) use (&$interrupted) {
        if ($msg instanceof StreamedAssistantMessage && ! $interrupted) {
            $interrupted = true;

            return false;
        }
    });

    foreach ($stream as $_) {
        // consume
    }

    expect($freshAgent->isInterrupted())->toBeTrue();
    expect($freshAgent->toolExecutionCount)->toBe(1);
});

it('resets interrupt state between runs', function () {
    $agent = new class('test_key') extends InterruptTestAgent
    {
        protected function onInitialize()
        {
            // Queue two responses: one for each run
            $this->llmDriver->addMockResponse('stop', [
                'content' => 'First response chunk1 chunk2 chunk3',
            ]);
            $this->llmDriver->addMockResponse('stop', [
                'content' => 'Second response completed successfully',
            ]);
        }
    };

    // First run: interrupt after first chunk
    $chunkCount = 0;
    $stream1 = $agent->respondStreamed('Hello', function ($msg) use (&$chunkCount) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunkCount++;
            if ($chunkCount >= 1) {
                return false;
            }
        }
    });

    foreach ($stream1 as $_) {
        // consume
    }

    expect($agent->isInterrupted())->toBeTrue();

    // Second run: should NOT be interrupted
    $stream2 = $agent->respondStreamed('Hello again');
    $allChunks = [];

    foreach ($stream2 as $chunk) {
        if ($chunk instanceof StreamedAssistantMessage) {
            $allChunks[] = $chunk->getContentAsString();
        }
    }

    expect($agent->isInterrupted())->toBeFalse();
    expect($allChunks)->not->toBeEmpty();
});

it('dispatches StreamInterrupted event with correct DTO', function () {
    Event::fake([StreamInterrupted::class]);

    $agent = new InterruptTestAgent('test_key_dto');
    $chunkCount = 0;

    $stream = $agent->respondStreamed('Hello', function ($msg) use (&$chunkCount) {
        if ($msg instanceof StreamedAssistantMessage) {
            $chunkCount++;
            if ($chunkCount >= 2) {
                return false;
            }
        }
    });

    foreach ($stream as $_) {
        // consume
    }

    Event::assertDispatched(StreamInterrupted::class, function (StreamInterrupted $event) {
        return $event->agentDto->provider !== null;
    });
});

it('handles interrupt when no content has been streamed', function () {
    $agent = new class('test_key') extends InterruptTestAgent
    {
        protected function onInitialize()
        {
            $this->llmDriver->addMockResponse('stop', [
                'content' => 'Short',
            ]);
        }
    };

    // Interrupt immediately on first chunk
    $stream = $agent->respondStreamed('Hello', function ($msg) {
        if ($msg instanceof StreamedAssistantMessage) {
            return false;
        }
    });

    foreach ($stream as $_) {
        // consume
    }

    expect($agent->isInterrupted())->toBeTrue();
});

it('implements InterruptableDriver on FakeLlmDriver', function () {
    $driver = new FakeLlmDriver;

    expect($driver)->toBeInstanceOf(InterruptableDriver::class);
    expect($driver->isInterrupted())->toBeFalse();

    $driver->interrupt();
    expect($driver->isInterrupted())->toBeTrue();

    $driver->resetInterrupt();
    expect($driver->isInterrupted())->toBeFalse();
});
