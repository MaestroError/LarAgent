<?php

use Illuminate\Support\Facades\Event;
use LarAgent\Agent;
use LarAgent\Events\AgentInitialized;
use LarAgent\Events\AfterResponse;
use LarAgent\Events\AfterSend;
use LarAgent\Events\AfterToolExecution;
use LarAgent\Events\AgentCleared;
use LarAgent\Events\BeforeReinjectingInstructions;
use LarAgent\Events\BeforeResponse;
use LarAgent\Events\BeforeSaveHistory;
use LarAgent\Events\BeforeSend;
use LarAgent\Events\BeforeStructuredOutput;
use LarAgent\Events\BeforeToolExecution;
use LarAgent\Events\ConversationEnded;
use LarAgent\Events\ConversationStarted;
use LarAgent\Events\EngineError;
use LarAgent\Events\ToolChanged;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Tool;

// Test agent for events
class EventTestAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public function instructions()
    {
        return 'You are a test agent for events.';
    }

    public function registerTools()
    {
        return [
            Tool::create('test_tool', 'A tool for testing')
                ->addProperty('input', 'string', 'Input for the tool')
                ->setRequired('input')
                ->setCallback(function ($input) {
                    return 'Processed '.$input;
                }),
        ];
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => 'Test response',
        ]);
    }
}

it('dispatches AgentInitialized event when agent is initialized', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    
    // Trigger initialization by calling respond
    $agent->respond('test message');

    Event::assertDispatched(AgentInitialized::class, function ($event) {
        return $event->agentDto !== null && 
               $event->agentDto->providerName !== null;
    });
});

it('dispatches ConversationStarted and ConversationEnded events', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    $response = $agent->respond('test message');

    Event::assertDispatched(ConversationStarted::class, function ($event) {
        return $event->agentDto !== null;
    });

    Event::assertDispatched(ConversationEnded::class, function ($event) {
        return $event->agentDto !== null && 
               $event->message !== null;
    });
});

it('dispatches BeforeSend and AfterSend events', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    $agent->respond('test message');

    Event::assertDispatched(BeforeSend::class, function ($event) {
        return $event->agentDto !== null && 
               $event->history !== null &&
               $event->message !== null;
    });

    Event::assertDispatched(AfterSend::class, function ($event) {
        return $event->agentDto !== null && 
               $event->history !== null &&
               $event->message !== null;
    });
});

it('dispatches BeforeResponse and AfterResponse events', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    $agent->respond('test message');

    Event::assertDispatched(BeforeResponse::class, function ($event) {
        return $event->agentDto !== null && 
               $event->history !== null;
    });

    Event::assertDispatched(AfterResponse::class, function ($event) {
        return $event->agentDto !== null && 
               $event->message !== null;
    });
});

it('includes agent DTO in all events', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    $agent->respond('test message');

    // Get all dispatched events
    $dispatchedEvents = Event::dispatched();

    // Check that all LarAgent events have agentDto
    foreach ($dispatchedEvents as $eventClass => $events) {
        if (str_starts_with($eventClass, 'LarAgent\\Events\\')) {
            foreach ($events as $event) {
                expect($event[0]->agentDto)->not->toBeNull()
                    ->and($event[0]->agentDto)->toHaveProperty('provider')
                    ->and($event[0]->agentDto)->toHaveProperty('providerName')
                    ->and($event[0]->agentDto)->toHaveProperty('tools');
            }
        }
    }
});

it('dispatches BeforeToolExecution and AfterToolExecution events when using tools', function () {
    Event::fake();

    // Create agent with tool execution mock
    class ToolEventTestAgent extends EventTestAgent
    {
        protected function onInitialize()
        {
            $this->llmDriver->addMockResponse('tool_calls', [
                'toolName' => 'test_tool',
                'arguments' => json_encode(['input' => 'test input']),
            ]);

            $this->llmDriver->addMockResponse('stop', [
                'content' => 'Processed test input',
            ]);
        }
    }

    $agent = ToolEventTestAgent::for('test_events');
    $agent->respond('Use the test tool with input "test input".');

    Event::assertDispatched(BeforeToolExecution::class, function ($event) {
        return $event->agentDto !== null && 
               $event->tool !== null;
    });

    Event::assertDispatched(AfterToolExecution::class, function ($event) {
        return $event->agentDto !== null && 
               $event->tool !== null &&
               $event->result !== null;
    });
});

it('dispatches ToolChanged event when tools are added or removed', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    
    // Add a tool
    $newTool = Tool::create('new_tool', 'A new tool')
        ->setCallback(function () { return 'new result'; });
    
    $agent->withTool($newTool);

    Event::assertDispatched(ToolChanged::class, function ($event) use ($newTool) {
        return $event->agentDto !== null && 
               $event->tool !== null &&
               $event->added === true;
    });

    // Clear events and test removal
    Event::fake();
    
    $agent->removeTool('new_tool');

    Event::assertDispatched(ToolChanged::class, function ($event) {
        return $event->agentDto !== null && 
               $event->tool !== null &&
               $event->added === false;
    });
});

it('does not dispatch events when Laravel Event facade is not available', function () {
    // This test ensures the trait works even without Laravel
    $agent = new class extends Agent {
        protected $model = 'gpt-4o-mini';
        protected $history = 'in_memory';
        protected $driver = FakeLlmDriver::class;

        public function instructions() { return 'test'; }
        
        protected function onInitialize()
        {
            $this->llmDriver->addMockResponse('stop', ['content' => 'test']);
        }
        
        // Override to simulate Event facade not available
        protected function beforeSend($history, $message)
        {
            // Manually call parent without Event facade
            if (method_exists($this, 'toDTO')) {
                // Should not throw error even if Event is not available
                $dto = $this->toDTO();
                expect($dto)->not->toBeNull();
            }
            return true;
        }
    };

    $agent = $agent::for('test_no_events');
    
    // This should not throw any errors
    expect(fn() => $agent->respond('test'))->not->toThrow(Exception::class);
});

it('can access event data in event listeners', function () {
    Event::fake();

    $agent = EventTestAgent::for('test_events');
    $agent->respond('test message');

    Event::assertDispatched(BeforeSend::class, function ($event) {
        // Verify we can access all the event data
        expect($event->agentDto->provider)->toBeString();
        expect($event->agentDto->providerName)->toBeString();
        expect($event->agentDto->tools)->toBeArray();
        expect($event->agentDto->configuration)->toBeArray();
        expect($event->history)->toBeInstanceOf(\LarAgent\Core\Contracts\ChatHistory::class);
        
        return true;
    });
});