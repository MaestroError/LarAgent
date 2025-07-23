<?php

namespace LarAgent\Tests\LarAgent\Fakes;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

class FakeLlmDriver extends LlmDriver implements LlmDriverInterface
{
    protected array $mockResponses = [];

    public function addMockResponse(string $finishReason, array $responseData): void
    {
        $this->mockResponses[] = [
            'finishReason' => $finishReason,
            'responseData' => $responseData,
        ];
    }

    public function sendMessage(array $messages, array $options = []): AssistantMessage|ToolCallMessage
    {
        $this->setConfig($options);

        if (empty($this->mockResponses)) {
            throw new \Exception('No mock responses are defined.');
        }

        $mockResponse = array_shift($this->mockResponses);

        $finishReason = $mockResponse['finishReason'];
        $responseData = $mockResponse['responseData'];

        // Handle different finish reasons
        if ($finishReason === 'tool_calls') {
            $toolCallId = '12345';
            $toolCalls[] = new ToolCall($toolCallId, $responseData['toolName'], $responseData['arguments']);

            return new ToolCallMessage(
                $toolCalls,
                $this->toolCallsToMessage($toolCalls),
                $responseData['metaData'] ?? []
            );
        }

        if ($finishReason === 'stop') {
            return new AssistantMessage(
                $responseData['content'],
                $responseData['metaData'] ?? []
            );
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    /**
     * Send a message to the LLM and receive a streamed response.
     * This is a simplified implementation for testing purposes.
     *
     * @param  array  $messages  Array of messages to send
     * @param  array  $options  Configuration options
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return \Generator A generator that yields chunks of the response
     *
     * @throws \Exception
     */
    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        $this->setConfig($options);

        if (empty($this->mockResponses)) {
            throw new \Exception('No mock responses are defined.');
        }

        $mockResponse = array_shift($this->mockResponses);

        $finishReason = $mockResponse['finishReason'];
        $responseData = $mockResponse['responseData'];

        // Handle different finish reasons
        if ($finishReason === 'tool_calls') {
            $toolCallId = '12345';
            $toolCalls[] = new ToolCall($toolCallId, $responseData['toolName'], $responseData['arguments']);

            $toolCallMessage = new ToolCallMessage(
                $toolCalls,
                $this->toolCallsToMessage($toolCalls),
                $responseData['metaData'] ?? []
            );

            // Call the callback if provided
            if ($callback) {
                $callback($toolCallMessage);
            }

            yield $toolCallMessage;
        } elseif ($finishReason === 'stop') {
            $message = new AssistantMessage(
                $responseData['content'],
                $responseData['metaData'] ?? []
            );

            // Call the callback if provided
            if ($callback) {
                $callback($message);
            }

            yield $message;
        } else {
            throw new \Exception('Unexpected finish reason: '.$finishReason);
        }
    }

    public function toolCallsToMessage(array $toolCalls): array
    {
        $toolCallsArray = [];
        foreach ($toolCalls as $tc) {
            $toolCallsArray[] = $this->toolCallToContent($tc);
        }

        return [
            'role' => 'assistant',
            'tool_calls' => $toolCallsArray,
        ];
    }

    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        // Build toolCall message content from toolCall
        $content = json_decode($toolCall->getArguments(), true);
        $content[$toolCall->getToolName()] = $result;

        return [
            'role' => 'tool',
            'content' => json_encode($content),
            'tool_call_id' => $toolCall->getId(),
        ];
    }

    // Helper methods

    protected function toolCallToContent(ToolCallInterface $toolCall): array
    {
        return [
            'id' => $toolCall->getId(),
            'type' => 'function',
            'function' => [
                'name' => $toolCall->getToolName(),
                'arguments' => $toolCall->getArguments(),
            ],
        ];
    }
}
