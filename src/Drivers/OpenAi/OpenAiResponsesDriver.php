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

        $formattedInput = $this->formatter->formatMessages($messages);

        // Extract the first system/developer message as the instructions parameter
        $instructions = null;
        $formattedInput = $this->extractInstructions($formattedInput, $instructions);

        $payload = [
            'model' => $config->model ?? 'gpt-4o-mini',
            'input' => $formattedInput,
        ];

        if ($instructions !== null) {
            $payload['instructions'] = $instructions;
        }

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

            // Request encrypted reasoning content so reasoning items can be
            // replayed in subsequent turns for stateless multi-turn conversations.
            $payload['include'] = array_unique(array_merge(
                $payload['include'] ?? [],
                ['reasoning.encrypted_content']
            ));
        }

        // Add remaining extras (excluding reasoning_effort which is handled above)
        foreach ($extras as $key => $value) {
            if ($key === 'reasoning_effort') {
                continue;
            }
            $payload[$key] = $value;
        }

        // Structured output uses text.format instead of response_format
        // Responses API expects name, strict, and schema at the format level (not nested under json_schema)
        if ($this->structuredOutputEnabled()) {
            $wrapped = $this->wrapResponseSchema($this->getResponseSchema());
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $wrapped['name'],
                    'schema' => $wrapped['schema'],
                    'strict' => $wrapped['strict'] ?? true,
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

        $toolCalls = $this->formatter->extractToolCalls($responseArray);

        if (
            $finishReason === 'tool_calls'
            || (isset($payload['tool_choice']) && is_array($payload['tool_choice']) && ! empty($toolCalls))
        ) {
            $message = new ToolCallMessage($toolCalls);
            $message->setUsage($usage);

            // Store raw output items (reasoning + function_call) so they can be replayed
            // exactly as the API returned them. Reasoning models require the complete
            // output items (with their id fields) to be passed back.
            $rawOutputItems = $this->formatter->extractOutputItemsForReplay($responseArray);
            if (! empty($rawOutputItems)) {
                $message->setExtra('raw_output_items', $rawOutputItems);
            }

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
        $toolCalls = [];        // Keyed by item_id
        $rawOutputItems = null; // Complete output items from response.completed
        $hasToolCalls = false;

        foreach ($stream as $response) {
            $this->lastResponse = $response;
            $type = $response->event;
            $event = $response->response->toArray();

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
                $itemType = $item['type'] ?? '';

                if ($itemType === 'function_call') {
                    $itemId = $item['id'];
                    $callId = $item['call_id'];
                    $name = $item['name'];
                    $toolCalls[$itemId] = [
                        'call_id' => $callId,
                        'name' => $name,
                        'arguments' => '',
                    ];
                    $hasToolCalls = true;
                }

                continue;
            }

            // Function call arguments delta - uses item_id to identify the function call
            if ($type === 'response.function_call_arguments.delta') {
                $itemId = $event['item_id'];
                $delta = $event['delta'] ?? '';
                if (isset($toolCalls[$itemId])) {
                    $toolCalls[$itemId]['arguments'] .= $delta;
                }

                continue;
            }

            // Response completed - extract usage and raw output items
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

                // Extract complete output items for faithful replay
                $rawOutputItems = $this->formatter->extractOutputItemsForReplay($responseData);

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

            // Store raw output items for faithful replay on next request
            if (! empty($rawOutputItems)) {
                $toolCallMessage->setExtra('raw_output_items', $rawOutputItems);
            }

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
     * Extract the first system or developer message from formatted input
     * and return it as the instructions string.
     *
     * The Responses API has a dedicated `instructions` parameter that gives
     * high-level instructions priority over input messages.
     */
    protected function extractInstructions(array $formattedInput, ?string &$instructions): array
    {
        foreach ($formattedInput as $index => $item) {
            if (
                ($item['type'] ?? '') === 'message'
                && in_array($item['role'] ?? '', ['system', 'developer'])
            ) {
                // Extract text from the content array
                $text = '';
                foreach ($item['content'] ?? [] as $part) {
                    if (isset($part['text'])) {
                        $text .= $part['text'];
                    }
                }
                $instructions = $text;
                array_splice($formattedInput, $index, 1);

                return $formattedInput;
            }
        }

        return $formattedInput;
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
