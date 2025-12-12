<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;
use LarAgent\Usage\DataModels\Usage;

/**
 * Base class for OpenAI and OpenAI-compatible drivers
 * Contains shared functionality to avoid code duplication
 */
abstract class BaseOpenAiDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    protected MessageFormatter $formatter;

    public function __construct(DriverConfig|array $settings = [])
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
        return new OpenAiMessageFormatter;
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
     * @param  MessageArray  $messages  Array of messages to send
     * @param  DriverConfig|array  $overrideSettings  Optional settings to override driver defaults
     * @return AssistantMessage The response from the LLM
     *
     * @throws \Exception
     */
    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the OpenAI driver.');
        }

        // Prepare the payload with common settings
        $payload = $this->preparePayload($messages, $overrideSettings);

        // Make an API call to OpenAI ("/chat" endpoint)
        $this->lastResponse = $this->client->chat()->create($payload);
        $responseArray = $this->lastResponse->toArray();

        // Extract data using formatter
        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $usageData = $this->formatter->extractUsage($responseArray);
        $usage = ! empty($usageData) ? Usage::fromArray($usageData) : null;

        // If tool is forced, finish reason is 'stop', so to process forced tool, we need extra checks for "tool_choice"
        if (
            $finishReason === 'tool_calls'
            || (isset($payload['tool_choice']) && is_array($payload['tool_choice']) && isset($responseArray['choices'][0]['message']['tool_calls']))
        ) {
            // Extract tool calls using formatter
            $toolCalls = $this->formatter->extractToolCalls($responseArray);

            $message = new ToolCallMessage($toolCalls);
            $message->setUsage($usage);

            return $message;
        }

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseArray);

            if (isset($payload['n']) && $payload['n'] > 1) {
                $contentsArray = [];

                foreach ($responseArray['choices'] as $choice) {
                    $contentsArray[] = $choice['message']['content'];
                }

                // @todo: get rid of encoding/decoding the same data
                // Check: src\LarAgent.php 'processMessage' method
                $content = json_encode($contentsArray);
            }

            $message = new AssistantMessage($content);
            $message->setUsage($usage);

            return $message;
        }

        throw new \Exception('Unexpected finish reason: '.$finishReason);
    }

    /**
     * Send a message to the LLM and receive a streamed response.
     *
     * @param  MessageArray  $messages  Array of messages to send
     * @param  DriverConfig|array  $overrideSettings  Optional settings to override driver defaults
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return \Generator A generator that yields chunks of the response
     *
     * @throws \Exception
     */
    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('OpenAI API key is required to use the OpenAI driver.');
        }

        // Prepare the payload with common settings
        $payload = $this->preparePayload($messages, $overrideSettings);

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
                $streamedMessage->setUsage(new Usage(
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                    $response->usage->totalTokens
                ));
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
            $toolCallMessage = new ToolCallMessage($toolCallObjects);

            // Transfer usage from streamed message if available
            if ($streamedMessage->getUsage() !== null) {
                $toolCallMessage->setUsage($streamedMessage->getUsage());
            }

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
     * @param  DriverConfig|array  $overrideSettings  Optional settings to override driver defaults
     * @return array The prepared payload
     */
    protected function preparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        // Merge driver config with override settings
        $overrideConfig = DriverConfig::wrap($overrideSettings);
        $config = $this->getDriverConfig()->merge($overrideConfig);

        // Format messages using the formatter (converts Message objects to API format)
        $formattedMessages = $this->formatter->formatMessages($messages);

        // Build payload with known properties
        $payload = [
            'model' => $config->model ?? 'gpt-4o-mini',
            'messages' => $formattedMessages,
        ];

        // Add optional known properties
        if ($config->has('temperature')) {
            $payload['temperature'] = $config->temperature;
        }
        if ($config->has('maxCompletionTokens')) {
            $payload['max_completion_tokens'] = $config->maxCompletionTokens;
        }
        if ($config->has('n')) {
            $payload['n'] = $config->n;
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
        if ($config->has('toolChoice')) {
            $payload['tool_choice'] = $config->toolChoice;
        }
        if ($config->has('parallelToolCalls')) {
            $payload['parallel_tool_calls'] = $config->parallelToolCalls;
        }
        if ($config->has('modalities')) {
            $payload['modalities'] = $config->modalities;
        }
        if ($config->has('audio')) {
            $payload['audio'] = $config->audio;
        }

        // Add any extra/custom settings
        foreach ($config->getExtras() as $key => $value) {
            $payload[$key] = $value;
        }

        // Set the response format if "responseSchema" is provided
        if ($this->structuredOutputEnabled()) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $this->wrapResponseSchema($this->getResponseSchema()),
            ];
        }

        // Add tools to payload if any are registered
        if (! empty($this->tools)) {
            $payload['tools'] = $this->formatter->formatTools(array_values($this->tools));
        }

        return $payload;
    }

    /**
     * Wrap a JSON schema in OpenAI's required format with name, strict, and schema.
     * Also ensures additionalProperties: false is set on all objects for strict mode.
     * Preserves existing values for name, strict, and additionalProperties if already set.
     *
     * @param  array  $schema  The schema to wrap (can be raw or already wrapped)
     * @return array The wrapped schema ready for OpenAI API
     */
    protected function wrapResponseSchema(array $schema): array
    {
        // If already wrapped with name and schema keys, preserve existing values
        if (isset($schema['name']) && isset($schema['schema'])) {
            return [
                'name' => $schema['name'],
                'schema' => $this->ensureAdditionalPropertiesFalse($schema['schema']),
                'strict' => $schema['strict'] ?? true,
            ];
        }

        // Raw schema - wrap it, preserving any existing additionalProperties settings
        $wrappedSchema = $this->ensureAdditionalPropertiesFalse($schema);

        return [
            'name' => $schema['title'] ?? $this->generateSchemaName($schema),
            'schema' => $wrappedSchema,
            'strict' => true,
        ];
    }

    /**
     * Generate a schema name from the schema structure.
     *
     * @param  array  $schema  The schema to generate name from
     * @return string Generated schema name
     */
    protected function generateSchemaName(array $schema): string
    {
        // Try to derive name from schema title or properties
        if (isset($schema['title'])) {
            return $this->toSnakeCase($schema['title']);
        }

        // Use first few property names to create a meaningful name
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $keys = array_slice(array_keys($schema['properties']), 0, 3);
            if (! empty($keys)) {
                return 'response_'.implode('_', $keys);
            }
        }

        return 'structured_response';
    }

    /**
     * Convert a string to snake_case.
     *
     * @param  string  $string  The string to convert
     * @return string Snake case string
     */
    protected function toSnakeCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace(' ', '_', $string)));
    }

    /**
     * Recursively ensure additionalProperties: false is set on all objects.
     * This is required for OpenAI's strict mode.
     * Preserves existing additionalProperties value if already set.
     *
     * @param  array  $schema  The schema to process
     * @return array Schema with additionalProperties: false on all objects (unless already set)
     */
    protected function ensureAdditionalPropertiesFalse(array $schema): array
    {
        // If this is an object type, add additionalProperties: false only if not already set
        if (isset($schema['type']) && $schema['type'] === 'object') {
            if (! array_key_exists('additionalProperties', $schema)) {
                $schema['additionalProperties'] = false;
            }
        }

        // Process nested properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $propSchema) {
                if (is_array($propSchema)) {
                    $schema['properties'][$key] = $this->ensureAdditionalPropertiesFalse($propSchema);
                }
            }
        }

        // Process array items
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->ensureAdditionalPropertiesFalse($schema['items']);
        }

        // Process oneOf/anyOf/allOf
        foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                foreach ($schema[$combinator] as $index => $subSchema) {
                    if (is_array($subSchema)) {
                        $schema[$combinator][$index] = $this->ensureAdditionalPropertiesFalse($subSchema);
                    }
                }
            }
        }

        // Process $defs definitions
        if (isset($schema['$defs']) && is_array($schema['$defs'])) {
            foreach ($schema['$defs'] as $defName => $defSchema) {
                if (is_array($defSchema)) {
                    $schema['$defs'][$defName] = $this->ensureAdditionalPropertiesFalse($defSchema);
                }
            }
        }

        return $schema;
    }
}
