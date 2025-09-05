<?php

namespace LarAgent\Core\Traits;

use Illuminate\Support\Facades\Event;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Events\AfterResponse;
use LarAgent\Events\AfterSend;
use LarAgent\Events\AfterToolExecution;
use LarAgent\Events\AgentCleared;
use LarAgent\Events\AgentInitialized;
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

trait Events
{
    /**
     * Registry of method names to event classes for automatic dispatching.
     */
    protected array $eventHooks = [];

    /**
     * Track which lifecycle events have been dispatched to avoid duplicates.
     */
    protected array $dispatchedEvents = [];

    /**
     * Check if Laravel events can be dispatched.
     */
    protected function canDispatchLaravelEvents(): bool
    {
        return class_exists('Illuminate\Support\Facades\Event') && method_exists($this, 'toDTO');
    }

    /**
     * Register event hooks for automatic dispatching.
     */
    protected function registerEventHooks(): void
    {
        $this->eventHooks = [
            'onInitialize' => AgentInitialized::class,
            'onConversationStart' => ConversationStarted::class,
            'onConversationEnd' => ConversationEnded::class,
            'onToolChange' => ToolChanged::class,
            'onClear' => AgentCleared::class,
            'onEngineError' => EngineError::class,
        ];
    }

    /**
     * Register an event hook for a method.
     */
    protected function registerEventHook(string $methodName, string $eventClass): void
    {
        $this->eventHooks[$methodName] = $eventClass;
    }

    /**
     * Ensure a lifecycle event is dispatched, even if the override method doesn't call parent.
     */
    protected function ensureEventDispatched(string $methodName, array $parameters = []): void
    {
        // Create a unique key for this event dispatch
        $eventKey = $methodName . '_' . serialize($parameters);
        
        // Only dispatch once per unique event
        if (isset($this->dispatchedEvents[$eventKey])) {
            return;
        }

        $this->dispatchedEvents[$eventKey] = true;

        if (!$this->canDispatchLaravelEvents() || !isset($this->eventHooks[$methodName])) {
            return;
        }

        $eventClass = $this->eventHooks[$methodName];
        $event = $this->createEventInstance($eventClass, $methodName, $parameters);
        
        if ($event) {
            Event::dispatch($event);
        }
    }

    /**
     * Create event instance based on event class and method parameters.
     */
    protected function createEventInstance(string $eventClass, string $methodName, array $parameters)
    {
        $agentDto = $this->toDTO();

        switch ($eventClass) {
            case AgentInitialized::class:
                return new AgentInitialized($agentDto);
                
            case ConversationStarted::class:
                return new ConversationStarted($agentDto);
                
            case ConversationEnded::class:
                $message = $parameters[0] ?? null;
                return new ConversationEnded($message, $agentDto);
                
            case ToolChanged::class:
                $tool = $parameters[0] ?? null;
                $added = $parameters[1] ?? true;
                return new ToolChanged($tool, $added, $agentDto);
                
            case AgentCleared::class:
                return new AgentCleared($agentDto);
                
            case EngineError::class:
                $throwable = $parameters[0] ?? null;
                return new EngineError($throwable, $agentDto);
                
            default:
                return null;
        }
    }

    /**
     * Event triggered before reinjecting instructions.
     *
     * @return bool|null
     */
    protected function beforeReinjectingInstructions(ChatHistoryInterface $chatHistory)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeReinjectingInstructions($chatHistory, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered before sending a message. (Before adding message in chat history)
     *
     * @return bool|null
     */
    protected function beforeSend(ChatHistoryInterface $history, ?MessageInterface $message)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeSend($history, $message, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered after sending a message. (After adding LLM response to Chat history)
     *
     * @return bool|null
     */
    protected function afterSend(ChatHistoryInterface $history, MessageInterface $message)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new AfterSend($history, $message, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered before saving chat history.
     *
     * @return bool|null
     */
    protected function beforeSaveHistory(ChatHistoryInterface $history)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeSaveHistory($history, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered before getting a response. (Before sending message to LLM)
     *
     * @return bool|null
     */
    protected function beforeResponse(ChatHistoryInterface $history, ?MessageInterface $message)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeResponse($history, $message, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered after getting a response. (After receiving message from LLM)
     *
     * @return bool|null
     */
    protected function afterResponse(MessageInterface $message)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new AfterResponse($message, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered before executing a tool.
     *
     * @return bool|null
     */
    protected function beforeToolExecution(ToolInterface $tool)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeToolExecution($tool, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered after executing a tool.
     *
     * @param  mixed  $result
     * @return bool|null
     */
    protected function afterToolExecution(ToolInterface $tool, &$result)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new AfterToolExecution($tool, $result, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered before structured output.
     *
     * @return bool|null
     */
    protected function beforeStructuredOutput(array &$response)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new BeforeStructuredOutput($response, $this->toDTO()));
        }

        return true;
    }

    /**
     * Event triggered when the agent is fully initialized.
     */
    protected function onInitialize()
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__);

        // Triggered when the agent is fully initialized
    }

    /**
     * Event triggered at start of `respond` method.
     */
    protected function onConversationStart()
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__);

        // Triggered when a new conversation starts
    }

    /**
     * Event triggered at end of `respond` method.
     */
    protected function onConversationEnd(MessageInterface|array|null $message)
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__, [$message]);

        // Triggered when a conversation ends
    }

    /**
     * Event triggered when a tool is added or removed.
     */
    protected function onToolChange(ToolInterface $tool, bool $added = true)
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__, [$tool, $added]);

        // Triggered when a tool is added or removed
    }

    /**
     * Event triggered when the agent state is cleared.
     */
    protected function onClear()
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__);

        // Triggered when the agent state is cleared
    }

    /**
     * Event triggered when the agent is being terminated.
     */
    protected function onTerminate()
    {
        // Triggered when the agent is being terminated
    }

    /**
     * Event triggered when an engine error occurs.
     */
    protected function onEngineError(\Throwable $th)
    {
        // Ensure the event is dispatched through the registry system
        $this->ensureEventDispatched(__FUNCTION__, [$th]);

        // Triggered when an engine error occurs
    }
}
