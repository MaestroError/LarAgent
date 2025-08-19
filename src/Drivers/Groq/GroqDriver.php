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
        $apiUrl = $settings['api_url'] ?? null;

        $options = [];
        if ($apiUrl) {
            $options['baseUrl'] = rtrim($apiUrl, '/');
        }

        $this->client = $apiKey ? new Groq($apiKey, $options) : null;
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
        $finishReason = $response['choices'][0]['finish_reason'];

        // If the model wants to call a tool, return a ToolCallMessage for LarAgent to handle
        if ($finishReason === 'tool_calls' &&
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

            $toolCallMessage = new ToolCallMessage(
                toolCalls: $toolCalls,
                message: $message,
                metadata: ['usage' => $response['usage'] ?? []]
            );

            return $toolCallMessage;
        }

        // Direct response, no tool_calls
        if ($finishReason === 'stop') {
            $content = $response['choices'][0]['message']['content'] ?? '';

            $assistantMessage = new AssistantMessage($content, ['usage' => $response['usage'] ?? []]);

            return $assistantMessage;
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Groq driver.');
        }

        $payload = $this->preparePayload($messages, $options);
        $payload['stream'] = true;

        if (! empty($payload['tools'])) {
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->client->chat()->completions()->create($payload);
        $stream = new StreamedAssistantMessage;

        $toolMode = false;
        $pending = []; // index => ['id' => null, 'name' => '', 'args' => '']
        $lastUsage = null; // prefer x_groq.usage; fallback to usage

        foreach ($response->chunks() as $chunk) {
            $this->lastResponse = $chunk;

            $choice = $chunk['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];

            // ---- USAGE: prefer x_groq.usage; fallback to usage ----
            if (isset($chunk['x_groq']['usage']) && is_array($chunk['x_groq']['usage'])) {
                $lastUsage = (array) $chunk['x_groq']['usage'];
                $stream->setUsage($lastUsage);
            } elseif (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $lastUsage = (array) $chunk['usage'];
                $stream->setUsage($lastUsage);
            }

            // ---- TOOL CALLS ----
            if (isset($delta['tool_calls'])) {
                $toolMode = true;

                foreach ($delta['tool_calls'] as $tc) {
                    $i = $tc['index'] ?? 0;
                    $pending[$i] = $pending[$i] ?? ['id' => null, 'name' => '', 'args' => ''];

                    if (isset($tc['id'])) {
                        $pending[$i]['id'] = $tc['id'];
                    }
                    if (isset($tc['function']['name'])) {
                        $pending[$i]['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $pending[$i]['args'] .= $tc['function']['arguments'];
                    }
                }
            }

            // ---- NORMAL CONTENT ----
            if (! $toolMode && isset($delta['content']) && $delta['content'] !== '') {
                $stream->appendContent($delta['content']);

                if ($callback) {
                    $callback($stream);
                }
                yield $stream;
            }

            // ---- FINISH REASON ----
            if (isset($choice['finish_reason'])) {
                $finish = $choice['finish_reason'];

                // Tool calls finished (or stop after tool deltas): emit ToolCallMessage
                if ($toolMode && ($finish === 'tool_calls' || $finish === 'stop')) {
                    $toolCalls = [];
                    foreach ($pending as $tc) {
                        $toolCalls[] = new ToolCall(
                            $tc['id'] ?? uniqid('tool_', true),
                            $tc['name'] ?? '',
                            $tc['args'] !== '' ? $tc['args'] : '{}'
                        );
                    }

                    $msg = $this->toolCallsToMessage($toolCalls);
                    $toolMsg = new ToolCallMessage(
                        toolCalls: $toolCalls,
                        message: $msg,
                        metadata: ['usage' => $lastUsage ?? []]
                    );

                    if ($callback) {
                        $callback($toolMsg);
                    }
                    yield $toolMsg;

                    return;
                }

                // Normal completion of assistant content
                if ($finish === 'stop') {
                    if ($lastUsage) {
                        $stream->setUsage($lastUsage);
                    }
                    $stream->setComplete(true);

                    if ($callback) {
                        $callback($stream);
                    }

                    return;
                }
            }
        }

        // No explicit finish_reason — finalize stream message
        $stream->setComplete(true);

        yield $stream;
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
}
