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
     * Mapping of method names to their corresponding event classes.
     */
    protected array $eventMapping = [
        // Lifecycle events
        'onInitialize' => AgentInitialized::class,
        'onConversationStart' => ConversationStarted::class,
        'onConversationEnd' => ConversationEnded::class,
        'onToolChange' => ToolChanged::class,
        'onClear' => AgentCleared::class,
        'onEngineError' => EngineError::class,
        
        // Hook events (before/after)
        'beforeReinjectingInstructions' => BeforeReinjectingInstructions::class,
        'beforeSend' => BeforeSend::class,
        'afterSend' => AfterSend::class,
        'beforeSaveHistory' => BeforeSaveHistory::class,
        'beforeResponse' => BeforeResponse::class,
        'afterResponse' => AfterResponse::class,
        'beforeToolExecution' => BeforeToolExecution::class,
        'afterToolExecution' => AfterToolExecution::class,
        'beforeStructuredOutput' => BeforeStructuredOutput::class,
    ];

    /**
     * Check if Laravel events can be dispatched.
     */
    protected function canDispatchLaravelEvents(): bool
    {
        return class_exists('Illuminate\Support\Facades\Event') && method_exists($this, 'toDTO');
    }

    /**
     * Call an event method and dispatch its corresponding Laravel event.
     *
     * @param string $functionName The name of the method to call
     * @param array $args The arguments to pass to both the event and the method
     * @return mixed The result of the method call
     */
    protected function callEvent(string $functionName, array $args = []): mixed
    {
        // Dispatch Laravel event if available and method is mapped
        if ($this->canDispatchLaravelEvents() && isset($this->eventMapping[$functionName])) {
            $eventClass = $this->eventMapping[$functionName];
            $event = $this->createEventInstance($eventClass, $functionName, $args);
            
            if ($event) {
                Event::dispatch($event);
            }
        }

        // Call the actual method if it exists
        if (method_exists($this, $functionName)) {
            return $this->$functionName(...$args);
        }

        return null;
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

            // Hook events
            case BeforeReinjectingInstructions::class:
                $chatHistory = $parameters[0] ?? null;
                return new BeforeReinjectingInstructions($chatHistory, $agentDto);

            case BeforeSend::class:
                $history = $parameters[0] ?? null;
                $message = $parameters[1] ?? null;
                return new BeforeSend($history, $message, $agentDto);

            case AfterSend::class:
                $history = $parameters[0] ?? null;
                $message = $parameters[1] ?? null;
                return new AfterSend($history, $message, $agentDto);

            case BeforeSaveHistory::class:
                $history = $parameters[0] ?? null;
                return new BeforeSaveHistory($history, $agentDto);

            case BeforeResponse::class:
                $history = $parameters[0] ?? null;
                $message = $parameters[1] ?? null;
                return new BeforeResponse($history, $message, $agentDto);

            case AfterResponse::class:
                $message = $parameters[0] ?? null;
                return new AfterResponse($message, $agentDto);

            case BeforeToolExecution::class:
                $tool = $parameters[0] ?? null;
                return new BeforeToolExecution($tool, $agentDto);

            case AfterToolExecution::class:
                $tool = $parameters[0] ?? null;
                $result = $parameters[1] ?? null;
                return new AfterToolExecution($tool, $result, $agentDto);

            case BeforeStructuredOutput::class:
                $response = $parameters[0] ?? null;
                return new BeforeStructuredOutput($response, $agentDto);
                
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
        return true;
    }

    /**
     * Event triggered before sending a message. (Before adding message in chat history)
     *
     * @return bool|null
     */
    protected function beforeSend(ChatHistoryInterface $history, ?MessageInterface $message)
    {
        return true;
    }

    /**
     * Event triggered after sending a message. (After adding LLM response to Chat history)
     *
     * @return bool|null
     */
    protected function afterSend(ChatHistoryInterface $history, MessageInterface $message)
    {
        return true;
    }

    /**
     * Event triggered before saving chat history.
     *
     * @return bool|null
     */
    protected function beforeSaveHistory(ChatHistoryInterface $history)
    {
        return true;
    }

    /**
     * Event triggered before getting a response. (Before sending message to LLM)
     *
     * @return bool|null
     */
    protected function beforeResponse(ChatHistoryInterface $history, ?MessageInterface $message)
    {
        return true;
    }

    /**
     * Event triggered after getting a response. (After receiving message from LLM)
     *
     * @return bool|null
     */
    protected function afterResponse(MessageInterface $message)
    {
        return true;
    }

    /**
     * Event triggered before executing a tool.
     *
     * @return bool|null
     */
    protected function beforeToolExecution(ToolInterface $tool)
    {
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
        return true;
    }

    /**
     * Event triggered before structured output.
     *
     * @return bool|null
     */
    protected function beforeStructuredOutput(array &$response)
    {
        return true;
    }

    /**
     * Event triggered when the agent is fully initialized.
     */
    protected function onInitialize()
    {
        // Triggered when the agent is fully initialized
    }

    /**
     * Event triggered at start of `respond` method.
     */
    protected function onConversationStart()
    {
        // Triggered when a new conversation starts
    }

    /**
     * Event triggered at end of `respond` method.
     */
    protected function onConversationEnd(MessageInterface|array|null $message)
    {
        // Triggered when a conversation ends
    }

    /**
     * Event triggered when a tool is added or removed.
     */
    protected function onToolChange(ToolInterface $tool, bool $added = true)
    {
        // Triggered when a tool is added or removed
    }

    /**
     * Event triggered when the agent state is cleared.
     */
    protected function onClear()
    {
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
        // Triggered when an engine error occurs
    }
}
