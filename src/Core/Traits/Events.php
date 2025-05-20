<?php

namespace LarAgent\Core\Traits;

use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;

trait Events
{
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
