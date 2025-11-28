<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

/**
 * Base class for OpenAI and OpenAI-compatible drivers
 * Contains shared functionality to avoid code duplication
 */
abstract class BaseOpenAiDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    protected MessageFormatter $formatter;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);
        $this->formatter = $this->createFormatter();
    }

    /**
     * Create the message formatter for this driver.
     * Can be overridden by child classes to use a different formatter.
     */
    protected function createFormatter(): MessageFormatter
    {
        return new OpenAiMessageFormatter();
    }

    /**
     * Get the message formatter.
     */
    public function getFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    /**
     * Send a message to the LLM and receive a response.
     *
     * @param  array  $messages  Array of messages to send
     * @param  array  $options  Configuration options
     * @return AssistantMessage The response from the LLM
     *
     * @throws \Exception
     */
    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the OpenAI driver.');
        }

        // Prepare the payload with common settings
        $payload = $this->preparePayload($messages, $options);

        // Make an API call to OpenAI ("/chat" endpoint)
        $this->lastResponse = $this->client->chat()->create($payload);
        $responseArray = $this->lastResponse->toArray();

        // Extract data using formatter
        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $metaData = [
            'usage' => $this->formatter->extractUsage($responseArray),
        ];

        // If tool is forced, finish reason is 'stop', so to process forced tool, we need extra checks for "tool_choice"
        if (
            $finishReason === 'tool_calls'
            || (isset($options['tool_choice']) && is_array($options['tool_choice']) && isset($responseArray['choices'][0]['message']['tool_calls']))
        ) {
            // Extract tool calls using formatter
            $toolCalls = $this->formatter->extractToolCalls($responseArray);

            return new ToolCallMessage($toolCalls, $metaData);
        }

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseArray);

            if (isset($options['n']) && $options['n'] > 1) {
                $contentsArray = [];

                foreach ($responseArray['choices'] as $choice) {
                    $contentsArray[] = $choice['message']['content'];
                }

                // @todo: get rid of encoding/decoding the same data
                // Check: src\LarAgent.php 'processMessage' method
                $content = json_encode($contentsArray);
            }

            return new AssistantMessage($content, $metaData);
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    /**
     * Send a message to the LLM and receive a streamed response.
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
        if (empty($this->client)) {
            throw new \Exception('OpenAI API key is required to use the OpenAI driver.');
        }

        // Prepare the payload with common settings
        $payload = $this->preparePayload($messages, $options);

        // Add stream-specific options
        $payload['stream'] = true;
        $payload['stream_options'] = [
            'include_usage' => true,
        ];

        // Create a streamed response
        $stream = $this->client->chat()->createStreamed($payload);

        // Initialize variables to track the streamed response
        $streamedMessage = new StreamedAssistantMessage;
        $toolCalls = [];
        $toolCallsSummary = []; // Store complete tool calls by ID
        $finishReason = null;
        $lastIndex = -1;

        // Process the stream
        foreach ($stream as $response) {
            $this->lastResponse = $response;

            // Check if this is the last chunk with usage information
            if (isset($response->usage)) {
                $streamedMessage->setUsage([
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'total_tokens' => $response->usage->totalTokens,
                ]);
                $streamedMessage->setComplete(true);

                // Execute callback if provided
                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;

                continue;
            }

            // Process the delta content
            $delta = $response->choices[0]->delta ?? null;
            $finishReason = $response->choices[0]->finishReason ?? $finishReason;

            // Handle tool calls
            if ($this->hasToolCalls($delta)) {
                $this->processToolCallDelta($delta, $toolCalls, $toolCallsSummary, $lastIndex);
            }
            // Handle regular content
            elseif (isset($delta->content)) {
                $streamedMessage->appendContent($delta->content);

                // Execute callback if provided
                if ($callback) {
                    $callback($streamedMessage);
                }

                // Yield the message
                yield $streamedMessage;
            } elseif (! isset($delta->content)) {
                $streamedMessage->resetLastChunk();
            }
        }

        // If we have tool calls, convert them to a ToolCallMessage
        if (! empty($toolCallsSummary) && $finishReason === 'tool_calls') {

            // Convert to ToolCall objects
            $toolCallObjects = array_map(function ($tc) {
                $id = $tc['id'] ?? 'tool_call_'.uniqid();
                $name = $tc['function']['name'] ?? '';
                $arguments = $tc['function']['arguments'] ?? '{}';

                return new ToolCall($id, $name, $arguments);
            }, array_values($toolCallsSummary));

            // Create ToolCallMessage directly - formatter handles conversion when needed
            $toolCallMessage = new ToolCallMessage(
                $toolCallObjects,
                $streamedMessage->getUsage() ? ['usage' => $streamedMessage->getUsage()] : []
            );

            // Execute callback if provided
            if ($callback) {
                $callback($toolCallMessage);
            }

            // Final yield with the complete ToolCallMessage
            yield $toolCallMessage;
        }
    }

    /**
     * Check if the delta contains tool calls
     *
     * @param  mixed  $delta  The delta object from the stream
     * @return bool True if the delta contains tool calls
     */
    protected function hasToolCalls(mixed $delta): bool
    {
        return isset($delta->toolCalls) && ! empty($delta->toolCalls);
    }

    /**
     * Process a tool call delta from the stream
     *
     * @param  mixed  $delta  The delta object from the stream
     * @param  array  &$toolCalls  Reference to the array of tool calls being built
     * @param  array  &$toolCallsSummary  Reference to the array of complete tool calls
     * @param  int  &$lastIndex  Reference to the last index seen
     */
    protected function processToolCallDelta(mixed $delta, array &$toolCalls, array &$toolCallsSummary, int &$lastIndex): void
    {
        foreach ($delta->toolCalls as $toolCallDelta) {
            $index = $toolCallDelta->index ?? 0;

            // Initialize tool call if it's new
            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => $toolCallDelta->id ?? null,
                    'type' => $toolCallDelta->type ?? 'function',
                    'function' => [
                        'name' => $toolCallDelta->function->name ?? '',
                        'arguments' => '',
                    ],
                ];
            }

            // Update tool call with delta information
            if (isset($toolCallDelta->function->name) && $toolCallDelta->function->name) {
                $toolCalls[$index]['function']['name'] = $toolCallDelta->function->name;
            }

            if (isset($toolCallDelta->function->arguments)) {
                $toolCalls[$index]['function']['arguments'] .= $toolCallDelta->function->arguments;
            }

            if (isset($toolCallDelta->id) && $toolCallDelta->id) {
                $toolCalls[$index]['id'] = $toolCallDelta->id;
            }

            // If we have a complete tool call with name and arguments, store it in summary
            if (! empty($toolCalls[$index]['function']['name']) &&
                strpos($toolCalls[$index]['function']['arguments'], '}') !== false &&
                json_decode($toolCalls[$index]['function']['arguments']) !== null) {

                // Store in summary by ID to avoid duplicates
                if (! empty($toolCalls[$index]['id'])) {
                    $toolCallsSummary[$toolCalls[$index]['id']] = $toolCalls[$index];
                } else {
                    // For tool calls without ID, use index as key
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
        $content = json_decode($toolCall->getArguments(), true);
        $content[$toolCall->getToolName()] = $result;

        return [
            'role' => 'tool',
            'content' => json_encode($content),
            'tool_call_id' => $toolCall->getId(),
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

    /**
     * Prepare the payload for API request with common settings
     *
     * @param  array  $messages  The messages to send
     * @param  array  $options  Configuration options
     * @return array The prepared payload
     */
    protected function preparePayload(array $messages, array $options = []): array
    {
        // Add model if from provider data if not provided via options
        if (empty($options['model'])) {
            $options['model'] = $this->getSettings()['model'] ?? 'gpt-4o-mini';
        }

        $this->setConfig($options);

        // Format messages using the formatter (converts Message objects to API format)
        $formattedMessages = $this->formatter->formatMessages($messages);

        $payload = array_merge($this->getConfig(), [
            'messages' => $formattedMessages,
        ]);

        // Set the response format if "responseSchema" is provided
        if ($this->structuredOutputEnabled()) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $this->getResponseSchema(),
            ];
        }

        // Add tools to payload if any are registered
        if (! empty($this->tools)) {
            $payload['tools'] = $this->formatter->formatTools(array_values($this->tools));
        }

        return $payload;
    }
}
