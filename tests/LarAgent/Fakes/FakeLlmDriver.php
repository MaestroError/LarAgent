<?php

namespace LarAgent\Tests\LarAgent\Fakes;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\ToolCall;
use LarAgent\Messages\DataModels\MessageArray;

class FakeLlmDriver extends LlmDriver implements LlmDriverInterface
{
    protected array $mockResponses = [];

    /**
     * Stores the last override settings passed to sendMessage/sendMessageStreamed for testing verification.
     */
    protected array $lastOverrideSettings = [];

    public function addMockResponse(string $finishReason, array $responseData): void
    {
        $this->mockResponses[] = [
            'finishReason' => $finishReason,
            'responseData' => $responseData,
        ];
    }

    /**
     * Get the merged config (settings + overrides) from the last call.
     * Used by tests to verify configuration was passed correctly.
     */
    public function getConfig(): array
    {
        return array_merge($this->getSettings(), $this->lastOverrideSettings);
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = new DriverConfig): AssistantMessage|ToolCallMessage
    {
        $this->lastOverrideSettings = $overrideSettings instanceof DriverConfig 
            ? $overrideSettings->toArray() 
            : $overrideSettings;

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

            $message = new ToolCallMessage($toolCalls);
            
            // Set usage if provided in metadata
            if (isset($responseData['metaData']['usage'])) {
                $message->setUsage(Usage::fromArray($responseData['metaData']['usage']));
            }
            
            return $message;
        }

        if ($finishReason === 'stop') {
            $message = new AssistantMessage($responseData['content']);
            
            // Set usage if provided in metadata
            if (isset($responseData['metaData']['usage'])) {
                $message->setUsage(Usage::fromArray($responseData['metaData']['usage']));
            }
            
            return $message;
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    /**
     * Send a message to the LLM and receive a streamed response.
     * This is a simplified implementation for testing purposes.
     *
     * @param  array  $messages  Array of messages to send
     * @param  array  $overrideSettings  Configuration overrides
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return \Generator A generator that yields chunks of the response
     *
     * @throws \Exception
     */
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
        $responseData = $mockResponse['responseData'];

        // Handle different finish reasons
        if ($finishReason === 'tool_calls') {
            $toolCallId = '12345';
            $toolCalls[] = new ToolCall($toolCallId, $responseData['toolName'], $responseData['arguments']);

            $toolCallMessage = new ToolCallMessage($toolCalls);
            
            // Set usage if provided in metadata
            if (isset($responseData['metaData']['usage'])) {
                $toolCallMessage->setUsage(Usage::fromArray($responseData['metaData']['usage']));
            }

            // Call the callback if provided
            if ($callback) {
                $callback($toolCallMessage);
            }

            yield $toolCallMessage;
        } elseif ($finishReason === 'stop') {
            $message = new AssistantMessage($responseData['content']);
            
            // Set usage if provided in metadata
            if (isset($responseData['metaData']['usage'])) {
                $message->setUsage(Usage::fromArray($responseData['metaData']['usage']));
            }

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
