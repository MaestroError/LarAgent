<?php

namespace LarAgent\Drivers\Groq;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\OpenAi\OpenAiMessageFormatter;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;
use LarAgent\Usage\DataModels\Usage;
use LucianoTonet\GroqPHP\Groq;

class GroqDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    protected MessageFormatter $formatter;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        $driverConfig = $this->getDriverConfig();
        $apiKey = $driverConfig->apiKey;
        $apiUrl = $driverConfig->apiUrl;

        $options = [];
        if ($apiUrl) {
            $options['baseUrl'] = rtrim($apiUrl, '/');
        }

        $this->client = $apiKey ? new Groq($apiKey, $options) : null;
        $this->formatter = $this->createFormatter();
    }

    /**
     * Create the message formatter for this driver.
     * Groq uses OpenAI-compatible format.
     */
    protected function createFormatter(): MessageFormatter
    {
        return new OpenAiMessageFormatter;
    }

    /**
     * Get the message formatter.
     */
    public function getFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Groq driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);

        $response = $this->client->chat()->completions()->create($payload);
        $this->lastResponse = $response;

        // Use formatter for extraction
        $finishReason = $this->formatter->extractFinishReason($response);
        $usageData = $this->formatter->extractUsage($response);
        $usage = ! empty($usageData) ? Usage::fromArray($usageData) : null;

        if ($finishReason === 'tool_calls') {
            $toolCalls = $this->formatter->extractToolCalls($response);

            $message = new ToolCallMessage($toolCalls);
            $message->setUsage($usage);

            return $message;
        }

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($response);

            $message = new AssistantMessage($content);
            $message->setUsage($usage);

            return $message;
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Groq driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);
        $payload['stream'] = true;

        $stream = new StreamedAssistantMessage;
        $toolCalls = [];
        $toolCallsSummary = [];
        $finishReason = null;

        $response = $this->client->chat()->completions()->create($payload);

        foreach ($response->chunks() as $chunk) {
            $this->lastResponse = $chunk;

            // Usage info (Groq uses `x_groq.usage` or `usage`)
            if (isset($chunk['x_groq']['usage']) && is_array($chunk['x_groq']['usage'])) {
                $usageData = $this->formatter->extractUsage(['usage' => $chunk['x_groq']['usage']]);
                $stream->setUsage(Usage::fromArray($usageData));
            } elseif (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $usageData = $this->formatter->extractUsage($chunk);
                $stream->setUsage(Usage::fromArray($usageData));
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
            } elseif (! isset($delta['content'])) {
                $stream->resetLastChunk();
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

            $toolMsg = new ToolCallMessage($toolCallObjects);

            // Transfer usage from streamed message if available
            if ($stream->getUsage() !== null) {
                $toolMsg->setUsage($stream->getUsage());
            }

            if ($callback) {
                $callback($toolMsg);
            }
            yield $toolMsg;

            return;
        }

        // Always yield final message
        $stream->setComplete(true);

        if ($callback) {
            $callback($stream);
        }

        yield $stream;
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

    /**
     * @deprecated Use formatter instead. This method is kept for backward compatibility.
     */
    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        return [
            'role' => 'tool',
            'name' => $toolCall->getToolName(),
            'tool_call_id' => $toolCall->getId(),
            'content' => is_string($result) ? $result : json_encode($result),
        ];
    }

    /**
     * @deprecated Use formatter instead. This method is kept for backward compatibility.
     */
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

    protected function preparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        // Merge driver config with override settings
        $overrideConfig = DriverConfig::wrap($overrideSettings);
        $config = $this->getDriverConfig()->merge($overrideConfig);

        // Format messages using the formatter (converts Message objects to API format)
        $formattedMessages = $this->formatter->formatMessages($messages);

        // Build payload with known properties
        $payload = [
            'model' => $config->model ?? 'llama-3.3-70b-versatile',
            'messages' => $formattedMessages,
        ];

        // Add optional known properties
        if ($config->has('temperature')) {
            $payload['temperature'] = $config->temperature;
        }
        if ($config->has('maxCompletionTokens')) {
            $payload['max_tokens'] = $config->maxCompletionTokens;
        }
        if ($config->has('topP')) {
            $payload['top_p'] = $config->topP;
        }
        if ($config->has('frequencyPenalty')) {
            $payload['frequency_penalty'] = $config->frequencyPenalty;
        }
        if ($config->has('presencePenalty')) {
            $payload['presence_penalty'] = $config->presencePenalty;
        }

        // Add any extra/custom settings
        foreach ($config->getExtras() as $key => $value) {
            $payload[$key] = $value;
        }

        // Structured output support
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
            $payload['tools'] = $this->formatter->formatTools(array_values($this->tools));
        }

        return $payload;
    }
}
