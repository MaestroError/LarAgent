<?php

namespace LarAgent\Drivers\Groq;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;
use LucianoTonet\GroqPHP\Groq;

class GroqDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);

        $apiKey = $settings['api_key'] ?? null;
        $this->client = $apiKey ? new Groq($apiKey) : null;
    }

    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Groq driver.');
        }

        $payload = $this->preparePayload($messages, $options);

        // Only include tool_choice if tools are defined
        if (! empty($payload['tools'])) {
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->client->chat()->completions()->create($payload);

        $this->lastResponse = $response;

        // Convert response to array if it's an object
        if (is_object($response)) {
            $response = json_decode(json_encode($response), true);
        }

        // If the model wants to call a tool, return a ToolCallMessage for LarAgent to handle
        if (
            isset($response['choices'][0]['message']['tool_calls']) &&
            ! empty($response['choices'][0]['message']['tool_calls'])
        ) {
            $toolCalls = [];
            foreach ($response['choices'][0]['message']['tool_calls'] as $toolCall) {

                $toolCalls[] = new ToolCall(
                    $toolCall['id'],
                    $toolCall['function']['name'] ?? '',
                    $toolCall['function']['arguments'] ?? '{}'
                );
            }

            $message = $this->toolCallsToMessage($toolCalls);

            return new ToolCallMessage($toolCalls, $message, ['usage' => $response['usage'] ?? []]);
        }

        // Direct response, no tool_calls
        $content = $response['choices'][0]['message']['content'] ?? '';

        return new AssistantMessage($content, ['usage' => $response['usage'] ?? []]);
    }

    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Groq driver.');
        }

        $payload = $this->preparePayload($messages, $options);
        $payload['stream'] = true;

        $response = $this->client->chat()->completions()->create($payload);
        $streamedMessage = new StreamedAssistantMessage;

        foreach ($response->chunks() as $chunk) {
            $this->lastResponse = $chunk;

            if (isset($chunk['choices'][0]['delta']['content'])) {
                $content = $chunk['choices'][0]['delta']['content'];
                $streamedMessage->appendContent($content);

                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;
            }

            if (isset($chunk['usage'])) {
                $streamedMessage->setUsage((array) $chunk['usage']);
                $streamedMessage->setComplete(true);
            }
        }
    }

    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        $content = json_decode($toolCall->getArguments(), true);
        $content[$toolCall->getToolName()] = $result;

        return [
            'role' => 'tool',
            'name' => $toolCall->getToolName(),
            'tool_call_id' => $toolCall->getId(),
            'content' => is_string($result) ? $result : json_encode($result),
        ];
    }

    public function toolCallsToMessage(array $toolCalls): array
    {
        $toolCallsArray = [];
        foreach ($toolCalls as $tc) {
            $toolCallsArray[] = [
                'id' => $tc->getId(),
                'type' => 'function',
                'function' => [
                    'name' => $tc->getToolName(),
                    'arguments' => $tc->getArguments(),
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'tool_calls' => $toolCallsArray,
        ];
    }

    protected function preparePayload(array $messages, array $options = []): array
    {
        if (empty($options['model'])) {
            $options['model'] = $this->getSettings()['model'] ?? 'llama-3.3-70b-versatile';
        }

        $this->setConfig($options);

        $payload = array_merge($this->getConfig(), [
            'messages' => $messages,
        ]);

        if ($this->structuredOutputEnabled()) {
            $schema = $this->getResponseSchema();

            if (is_array($schema) && isset($schema['schema']) && isset($schema['name'])) {
                // Valid json_schema format
                $payload['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schema['name'],
                        'schema' => $schema['schema'],
                    ],
                ];
            } elseif (is_array($schema) && ($schema['type'] ?? null) === 'json_object') {
                // json_object format
                $payload['response_format'] = [
                    'type' => 'json_object',
                ];
            }
        }

        if (! empty($this->tools)) {
            foreach ($this->getRegisteredTools() as $tool) {
                $payload['tools'][] = $this->formatToolForPayload($tool);
            }
        }

        return $payload;
    }

    public function structuredOutputEnabled(): bool
    {
        return isset($this->responseSchema);
    }

    public function getResponseSchema(): array
    {
        return $this->responseSchema ?? [];
    }
}
