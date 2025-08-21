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

        $response = $this->client->chat()->completions()->create($payload);
        $this->lastResponse = $response;

        $finishReason = $response['choices'][0]['finish_reason'] ?? null;
        $metaData = ['usage' => $response['usage'] ?? []];

        if (
            $finishReason === 'tool_calls'
            && isset($response['choices'][0]['message']['tool_calls'])
            && ! empty($response['choices'][0]['message']['tool_calls'])
        ) {
            $toolCalls = array_map(function ($tc) {
                return new ToolCall(
                    $tc['id'],
                    $tc['function']['name'] ?? '',
                    $tc['function']['arguments'] ?? '{}'
                );
            }, $response['choices'][0]['message']['tool_calls']);

            $toolCallMessage = new ToolCallMessage(
                $toolCalls,
                $this->toolCallsToMessage($toolCalls),
                $metaData
            );

            return $toolCallMessage;
        }

        if ($finishReason === 'stop') {
            $content = $response['choices'][0]['message']['content'] ?? '';
            $assistantMessage = new AssistantMessage($content, $metaData);

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

        $stream = new StreamedAssistantMessage;
        $toolCalls = [];
        $toolCallsSummary = [];
        $finishReason = null;
        $lastUsage = null;

        $response = $this->client->chat()->completions()->create($payload);

        foreach ($response->chunks() as $chunk) {
            $this->lastResponse = $chunk;

            // Usage info (Groq uses `x_groq.usage` or `usage`)
            if (isset($chunk['x_groq']['usage']) && is_array($chunk['x_groq']['usage'])) {
                $lastUsage = (array) $chunk['x_groq']['usage'];
                $stream->setUsage($lastUsage);
            } elseif (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $lastUsage = (array) $chunk['usage'];
                $stream->setUsage($lastUsage);
            }

            $choice = $chunk['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            $finishReason = $choice['finish_reason'] ?? $finishReason;

            // Tool calls
            if ($this->hasToolCalls($delta)) {
                $this->processToolCallDelta($delta, $toolCalls, $toolCallsSummary);
            }
            // Normal text
            elseif (isset($delta['content'])) {
                $stream->appendContent($delta['content']);

                if ($callback) {
                    $callback($stream);
                }
                yield $stream;
            }
        }

        // If we have tool calls, convert them to a ToolCallMessage
        if (! empty($toolCallsSummary) && $finishReason === 'tool_calls') {
            $toolCallObjects = array_map(function ($tc) {
                return new ToolCall(
                    $tc['id'] ?? 'tool_call_'.uniqid(),
                    $tc['function']['name'] ?? '',
                    $tc['function']['arguments'] ?? '{}'
                );
            }, array_values($toolCallsSummary));

            $toolMsg = new ToolCallMessage(
                $toolCallObjects,
                $this->toolCallsToMessage($toolCallObjects),
                $lastUsage ? ['usage' => $lastUsage] : []
            );

            if ($callback) {
                $callback($toolMsg);
            }
            yield $toolMsg;
        }

        // Normal assistant message
        if ($finishReason === 'stop') {
            if ($lastUsage) {
                $stream->setUsage($lastUsage);
            }
            $stream->setComplete(true);

            if ($callback) {
                $callback($stream);
            }

            yield $stream;
        }
    }

    protected function hasToolCalls(mixed $delta): bool
    {
        return isset($delta['tool_calls']) && ! empty($delta['tool_calls']);
    }

    protected function processToolCallDelta(mixed $delta, array &$toolCalls, array &$toolCallsSummary): void
    {
        foreach ($delta['tool_calls'] as $toolCallDelta) {
            $index = $toolCallDelta['index'] ?? 0;

            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => $toolCallDelta['id'] ?? null,
                    'type' => $toolCallDelta['type'] ?? 'function',
                    'function' => [
                        'name' => $toolCallDelta['function']['name'] ?? '',
                        'arguments' => '',
                    ],
                ];
            }

            if (! empty($toolCallDelta['function']['name'])) {
                $toolCalls[$index]['function']['name'] = $toolCallDelta['function']['name'];
            }

            if (isset($toolCallDelta['function']['arguments'])) {
                $toolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
            }

            if (! empty($toolCallDelta['id'])) {
                $toolCalls[$index]['id'] = $toolCallDelta['id'];
            }

            // If arguments parse as valid JSON, treat as complete
            if (! empty($toolCalls[$index]['function']['name']) &&
                strpos($toolCalls[$index]['function']['arguments'], '}') !== false &&
                json_decode($toolCalls[$index]['function']['arguments']) !== null
            ) {
                if (! empty($toolCalls[$index]['id'])) {
                    $toolCallsSummary[$toolCalls[$index]['id']] = $toolCalls[$index];
                } else {
                    $toolCallsSummary['index_'.$index] = $toolCalls[$index];
                }

                $toolCalls[$index]['function']['arguments'] = '';
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
                $payload['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schema['name'],
                        'schema' => $schema['schema'],
                    ],
                ];
            } elseif (is_array($schema) && ($schema['type'] ?? null) === 'json_object') {
                $payload['response_format'] = ['type' => 'json_object'];
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
