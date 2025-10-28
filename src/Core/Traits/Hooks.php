<?php

namespace LarAgent\Core\Traits;

use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;

trait Hooks
{
    // Before reinjecting instuctions
    protected array $beforeReinjectCallbacks = [];

    // Before send (Before adding message in chat history)
    protected array $beforeSendCallbacks = [];

    // After send (After adding LLM response to Chat history)
    protected array $afterSendCallbacks = [];

    // Before saving chat history
    protected array $beforeSaveCallbacks = [];

    // Before response (Before sending message to LLM)
    protected array $beforeResponseCallbacks = [];

    // After response (After receiving message from LLM)
    protected array $afterResponseCallbacks = [];

    // Before tool execution
    protected array $beforeToolExecutionCallbacks = [];

    // After tool execution
    protected array $afterToolExecutionCallbacks = [];

    // Before structured output response
    protected array $beforeStructuredOutputCallbacks = [];

    // Event methods

    // Before reinjecting instuctions
    public function beforeReinjectingInstructions(callable $callback): self
    {
        $this->beforeReinjectCallbacks[] = $callback;

        return $this;
    }

    protected function processBeforeReinjectingInstructions(ChatHistoryInterface $chatHistory): ?bool
    {
        foreach ($this->beforeReinjectCallbacks as $callback) {
            // ($agent)
            if ($callback($this, $chatHistory) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // Before send (Before adding message in chat history)
    public function beforeSend(callable $callback): self
    {
        $this->beforeSendCallbacks[] = $callback;

        return $this;
    }

    protected function processBeforeSend(ChatHistoryInterface $history, ?MessageInterface $message): ?bool
    {
        foreach ($this->beforeSendCallbacks as $callback) {
            // ($agent, $message)
            if ($callback($this, $history, $message) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // After send (After adding LLM response to Chat history)
    public function afterSend(callable $callback): self
    {
        $this->afterSendCallbacks[] = $callback;

        return $this;
    }

    protected function processAfterSend(ChatHistoryInterface $history, MessageInterface $message): ?bool
    {
        foreach ($this->afterSendCallbacks as $callback) {
            // ($agent, $history, $message)
            if ($callback($this, $history, $message) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // Before saving chat history
    public function beforeSaveHistory(callable $callback): self
    {
        $this->beforeSaveCallbacks[] = $callback;

        return $this;
    }

    protected function processBeforeSaveHistory(ChatHistoryInterface $history): ?bool
    {
        foreach ($this->beforeSaveCallbacks as $callback) {
            // ($agent, $history)
            if ($callback($this, $history) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // Before response (Before sending message to LLM)
    public function beforeResponse(callable $callback): self
    {
        $this->beforeResponseCallbacks[] = $callback;

        return $this;
    }

    protected function processBeforeResponse(ChatHistoryInterface $history, ?MessageInterface $message): ?bool
    {
        foreach ($this->beforeResponseCallbacks as $callback) {
            // ($agent, $history, $message)
            if ($callback($this, $history, $message) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // After response (After receiving message from LLM)
    public function afterResponse(callable $callback): self
    {
        $this->afterResponseCallbacks[] = $callback;

        return $this;
    }

    protected function processAfterResponse(MessageInterface $message): ?bool
    {
        foreach ($this->afterResponseCallbacks as $callback) {
            // ($agent, $message)
            if ($callback($this, $message) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // Before tool execution
    public function beforeToolExecution(callable $callback): self
    {
        $this->beforeToolExecutionCallbacks[] = $callback;

        return $this;
    }

    /**
     * Process before tool execution callbacks
     *
     * @param  ToolInterface  $tool  The tool being executed
     * @param  ToolCallInterface  $toolCall  The tool call object containing ID, name, and arguments
     * @return bool|null Returns false if execution should be skipped, true otherwise
     */
    protected function processBeforeToolExecution(ToolInterface $tool, ToolCallInterface $toolCall): ?bool
    {
        foreach ($this->beforeToolExecutionCallbacks as $callback) {
            // ($agent, $tool, $toolCall)
            if ($callback($this, $tool, $toolCall) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    // After tool execution
    public function afterToolExecution(callable $callback): self
    {
        $this->afterToolExecutionCallbacks[] = $callback;

        return $this;
    }

    /**
     * Process after tool execution callbacks
     *
     * @param  ToolInterface  $tool  The tool that was executed
     * @param  ToolCallInterface  $toolCall  The tool call object containing ID, name, and arguments
     * @param  mixed  $result  The result from tool execution (mutable by reference)
     * @return bool|null Returns false if result should be skipped, true otherwise
     */
    protected function processAfterToolExecution(ToolInterface $tool, ToolCallInterface $toolCall, mixed &$result): ?bool
    {
        foreach ($this->afterToolExecutionCallbacks as $callback) {
            // ($agent, $tool, $toolCall, &$result) to make $result mutable
            if ($callback($this, $tool, $toolCall, $result) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }

    public function beforeStructuredOutput(callable $callback): self
    {
        $this->beforeStructuredOutputCallbacks[] = $callback;

        return $this;
    }

    protected function processBeforeStructuredOutput(array &$response): ?bool
    {
        foreach ($this->beforeStructuredOutputCallbacks as $callback) {
            // ($agent, &$response)
            if ($callback($this, $response) === false) {
                return false; // Return false if a callback returns false
            }
        }

        return true;
    }
}
