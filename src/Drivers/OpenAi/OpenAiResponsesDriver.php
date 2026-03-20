<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;
use LarAgent\Usage\DataModels\Usage;
use OpenAI;

/**
 * Driver for the OpenAI Responses API (/v1/responses).
 *
 * Extends BaseOpenAiDriver to reuse strict-mode schema transformation code,
 * but overrides message sending and payload preparation for the different API format.
 */
class OpenAiResponsesDriver extends BaseOpenAiDriver
{
    protected mixed $client;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        $apiKey = $this->getDriverConfig()->apiKey;
        $this->client = $apiKey ? OpenAI::client($apiKey) : null;
    }

    protected function createFormatter(): MessageFormatter
    {
        return new OpenAiResponsesMessageFormatter;
    }

    /**
     * Prepare the payload for the Responses API.
     */
    protected function preparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        $overrideConfig = DriverConfig::wrap($overrideSettings);
        $config = $this->getDriverConfig()->merge($overrideConfig);

        $payload = [
            'model' => $config->model ?? 'gpt-4o-mini',
            'input' => $this->formatter->formatMessages($messages),
        ];

        if ($config->has('maxCompletionTokens')) {
            $payload['max_output_tokens'] = $config->maxCompletionTokens;
        }
        if ($config->has('temperature')) {
            $payload['temperature'] = $config->temperature;
        }
        if ($config->has('topP')) {
            $payload['top_p'] = $config->topP;
        }
        if ($config->has('toolChoice')) {
            $payload['tool_choice'] = $config->toolChoice;
        }
        if ($config->has('parallelToolCalls')) {
            $payload['parallel_tool_calls'] = $config->parallelToolCalls;
        }

        // Handle reasoning_effort via the reasoning field
        $extras = $config->getExtras();
        $reasoningEffort = $extras['reasoning_effort'] ?? null;
        if ($reasoningEffort) {
            $payload['reasoning'] = ['effort' => $reasoningEffort];
        }

        // Add remaining extras (excluding reasoning_effort which is handled above)
        foreach ($extras as $key => $value) {
            if ($key === 'reasoning_effort') {
                continue;
            }
            $payload[$key] = $value;
        }

        // Structured output uses text.format instead of response_format
        if ($this->structuredOutputEnabled()) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'json_schema' => $this->wrapResponseSchema($this->getResponseSchema()),
                ],
            ];
        }

        // Add tools in flat format with strict mode
        if (! empty($this->tools)) {
            $tools = $this->formatter->formatTools(array_values($this->tools));
            $payload['tools'] = $this->transformToolsForResponsesApi($tools);
        }

        return $payload;
    }

    /**
     * Transform tool definitions for Responses API strict mode.
     *
     * Unlike Chat Completions, Responses API uses flat tool format
     * (parameters directly on the tool, not nested under 'function').
     */
    protected function transformToolsForResponsesApi(array $tools): array
    {
        foreach ($tools as $index => $tool) {
            if (isset($tool['parameters']) && is_array($tool['parameters'])) {
                $tools[$index]['parameters'] = $this->transformSchemaForStrictMode(
                    $tool['parameters']
                );
                $tools[$index]['strict'] = true;
            }
        }

        return $tools;
    }

    /**
     * Send a message using the Responses API.
     */
    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the OpenAI Responses driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);

        $this->lastResponse = $this->client->responses()->create($payload);
        $responseArray = $this->lastResponse->toArray();

        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $usageData = $this->formatter->extractUsage($responseArray);
        $usage = ! empty($usageData) ? Usage::fromArray($usageData) : null;

        if (
            $finishReason === 'tool_calls'
            || (isset($payload['tool_choice']) && is_array($payload['tool_choice']))
                && ! empty($this->formatter->extractToolCalls($responseArray))
        ) {
            $toolCalls = $this->formatter->extractToolCalls($responseArray);
            $message = new ToolCallMessage($toolCalls);
            $message->setUsage($usage);

            return $message;
        }

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseArray);
            $message = new AssistantMessage($content);
            $message->setUsage($usage);

            return $message;
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    /**
     * Send a message using the Responses API with streaming.
     */
    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the OpenAI Responses driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);

        $stream = $this->client->responses()->createStreamed($payload);

        $streamedMessage = new StreamedAssistantMessage;
        $toolCalls = [];
        $hasToolCalls = false;

        foreach ($stream as $response) {
            $this->lastResponse = $response;
            $event = $response->toArray();
            $type = $event['type'] ?? '';

            // Text content delta
            if ($type === 'response.output_text.delta') {
                $delta = $event['delta'] ?? '';
                $streamedMessage->appendContent($delta);

                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;

                continue;
            }

            // New output item - track function calls
            if ($type === 'response.output_item.added') {
                $item = $event['item'] ?? [];
                if (($item['type'] ?? '') === 'function_call') {
                    $callId = $item['call_id'] ?? '';
                    $name = $item['name'] ?? '';
                    $toolCalls[$callId] = [
                        'call_id' => $callId,
                        'name' => $name,
                        'arguments' => '',
                    ];
                    $hasToolCalls = true;
                }

                continue;
            }

            // Function call arguments delta
            if ($type === 'response.function_call_arguments.delta') {
                $callId = $event['call_id'] ?? '';
                $delta = $event['delta'] ?? '';
                if (isset($toolCalls[$callId])) {
                    $toolCalls[$callId]['arguments'] .= $delta;
                }

                continue;
            }

            // Response completed - extract usage
            if ($type === 'response.completed') {
                $responseData = $event['response'] ?? [];
                $usageData = $responseData['usage'] ?? [];
                if (! empty($usageData)) {
                    $streamedMessage->setUsage(new Usage(
                        $usageData['input_tokens'] ?? 0,
                        $usageData['output_tokens'] ?? 0,
                        $usageData['total_tokens'] ?? (($usageData['input_tokens'] ?? 0) + ($usageData['output_tokens'] ?? 0))
                    ));
                }
                $streamedMessage->setComplete(true);

                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;

                continue;
            }
        }

        // If we accumulated tool calls, yield a ToolCallMessage
        if ($hasToolCalls && ! empty($toolCalls)) {
            $toolCallObjects = array_map(function ($tc) {
                return new ToolCall(
                    $tc['call_id'],
                    $tc['name'],
                    $tc['arguments'] ?: '{}'
                );
            }, array_values($toolCalls));

            $toolCallMessage = new ToolCallMessage($toolCallObjects);

            if ($streamedMessage->getUsage() !== null) {
                $toolCallMessage->setUsage($streamedMessage->getUsage());
            }

            if ($callback) {
                $callback($toolCallMessage);
            }

            yield $toolCallMessage;
        }
    }

    /**
     * @deprecated Use formatter instead.
     */
    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        $content = json_decode($toolCall->getArguments(), true);
        $content[$toolCall->getToolName()] = $result;

        return [
            'type' => 'function_call_output',
            'call_id' => $toolCall->getId(),
            'output' => json_encode($content),
        ];
    }

    /**
     * @deprecated Use formatter instead.
     */
    public function toolCallsToMessage(array $toolCalls): array
    {
        // Return first tool call in Responses API format
        $first = $toolCalls[0] ?? null;
        if (! $first) {
            return [];
        }

        return [
            'type' => 'function_call',
            'call_id' => $first->getId(),
            'name' => $first->getToolName(),
            'arguments' => $first->getArguments(),
        ];
    }
}
