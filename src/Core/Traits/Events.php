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
     * Check if Laravel events can be dispatched.
     */
    protected function canDispatchLaravelEvents(): bool
    {
        return class_exists('Illuminate\Support\Facades\Event') && method_exists($this, 'toDTO');
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
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new AgentInitialized($this->toDTO()));
        }

        // Triggered when the agent is fully initialized
    }

    /**
     * Event triggered at start of `respond` method.
     */
    protected function onConversationStart()
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new ConversationStarted($this->toDTO()));
        }

        // Triggered when a new conversation starts
    }

    /**
     * Event triggered at end of `respond` method.
     */
    protected function onConversationEnd(MessageInterface|array|null $message)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new ConversationEnded($message, $this->toDTO()));
        }

        // Triggered when a conversation ends
    }

    /**
     * Event triggered when a tool is added or removed.
     */
    protected function onToolChange(ToolInterface $tool, bool $added = true)
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new ToolChanged($tool, $added, $this->toDTO()));
        }

        // Triggered when a tool is added or removed
    }

    /**
     * Event triggered when the agent state is cleared.
     */
    protected function onClear()
    {
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new AgentCleared($this->toDTO()));
        }

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
        // Dispatch Laravel event if available
        if ($this->canDispatchLaravelEvents()) {
            Event::dispatch(new EngineError($th, $this->toDTO()));
        }

        // Triggered when an engine error occurs
    }
}
