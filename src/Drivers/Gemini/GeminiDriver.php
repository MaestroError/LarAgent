<?php

namespace LarAgent\Drivers\Gemini;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Usage\DataModels\Usage;
use RuntimeException;

class GeminiDriver extends LlmDriver
{
    protected Client $httpClient;

    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';

    protected GeminiMessageFormatter $formatter;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        $driverConfig = $this->getDriverConfig();

        if (empty($driverConfig->apiKey)) {
            throw new RuntimeException('Gemini driver requires an API key.');
        }

        $this->apiKey = $driverConfig->apiKey;

        // Configurable base URL
        $this->baseUrl = $driverConfig->apiUrl ?? $this->baseUrl;

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ],
        ]);

        $this->lastResponse = null;
        $this->formatter = $this->createFormatter();
    }

    /**
     * Create the message formatter for this driver.
     */
    protected function createFormatter(): GeminiMessageFormatter
    {
        return new GeminiMessageFormatter;
    }

    /**
     * Get the message formatter.
     */
    public function getFormatter(): GeminiMessageFormatter
    {
        return $this->formatter;
    }

    /**
     * Send a message to the LLM and receive a response using native Gemini API.
     */
    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        try {
            $payload = $this->preparePayload($messages, $overrideSettings);

            // Get merged config for model
            $overrideConfig = DriverConfig::wrap($overrideSettings);
            $config = $this->getDriverConfig()->merge($overrideConfig);
            $model = $config->model ?? 'gemini-1.5-flash-latest';

            $url = "models/{$model}:generateContent";

            $response = $this->httpClient->post($url, ['json' => $payload]);
            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->lastResponse = $responseData;

            return $this->handleResponse($responseData);
        } catch (RequestException $e) {
            throw new RuntimeException('Gemini API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle the API response and return appropriate message type.
     */
    protected function handleResponse(array $responseData): AssistantMessage
    {
        $usageData = $this->formatter->extractUsage($responseData);
        $usage = ! empty($usageData) ? Usage::fromArray($usageData) : null;

        // Use formatter's hasToolCalls since Gemini returns 'STOP' even with tool calls
        if ($this->formatter->hasToolCalls($responseData)) {
            $toolCalls = $this->formatter->extractToolCalls($responseData);

            $message = new ToolCallMessage($toolCalls);
            $message->setUsage($usage);

            return $message;
        }

        $finishReason = $this->formatter->extractFinishReason($responseData);

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseData);

            $message = new AssistantMessage($content);
            $message->setUsage($usage);

            // Extract and store thought signature for text responses (optional but recommended)
            $thoughtSignature = $this->formatter->extractThoughtSignature($responseData);
            if ($thoughtSignature !== null) {
                $message->setExtra('thought_signature', $thoughtSignature);
            }

            return $message;
        }

        if ($finishReason === 'content_filter') {
            $rawReason = $responseData['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new RuntimeException("Gemini API finished with reason: {$rawReason}");
        }

        throw new RuntimeException('Unexpected response format from Gemini API');
    }

    /**
     * @deprecated Use GeminiMessageFormatter::extractToolCalls() instead.
     *             This method is maintained for backwards compatibility.
     */
    protected function extractToolCalls(array $responseData): array
    {
        return $this->formatter->extractToolCalls($responseData);
    }

    /**
     * Send a message to the LLM and receive a streamed response.
     */
    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): Generator
    {
        try {
            $payload = $this->preparePayload($messages, $overrideSettings);

            // Get merged config for model
            $overrideConfig = DriverConfig::wrap($overrideSettings);
            $config = $this->getDriverConfig()->merge($overrideConfig);
            $model = $config->model ?? 'gemini-1.5-flash-latest';

            // Use streaming endpoint
            $url = "models/{$model}:streamGenerateContent";

            $response = $this->httpClient->post($url, [
                'query' => ['alt' => 'sse'],
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $streamedMessage = new StreamedAssistantMessage;
            $toolCallsSummary = [];
            $finishReason = null;
            $lastResponseData = null;

            while (! $stream->eof()) {
                $chunk = $stream->read(1024);
                $lines = explode("\n", $chunk);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || ! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6); // Remove 'data: ' prefix
                    if ($data === '[DONE]') {
                        break 2;
                    }

                    $responseData = json_decode($data, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    // Store last response data for usage information
                    $lastResponseData = $responseData;

                    // Get finish reason if available (use formatter for normalization)
                    if (isset($responseData['candidates'][0]['finishReason'])) {
                        $finishReason = $this->formatter->extractFinishReason($responseData);
                    }

                    // Check for tool calls (function calls in Gemini)
                    if (isset($responseData['candidates'][0]['content']['parts'])) {
                        $currentThoughtSignature = null;

                        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                            // Capture thought signature from any part (it appears alongside function calls or text)
                            if (isset($part['thoughtSignature'])) {
                                $currentThoughtSignature = $part['thoughtSignature'];
                            }

                            // Handle function calls
                            if (isset($part['functionCall'])) {
                                $functionCall = $part['functionCall'];
                                $toolCallId = 'tool_call_'.uniqid();

                                // For parallel function calls, only the first has the signature (per Gemini docs)
                                // Use empty($toolCallsSummary) to check across all chunks, not just current chunk
                                $isFirstToolCall = empty($toolCallsSummary);
                                $thoughtSignature = $isFirstToolCall ? ($part['thoughtSignature'] ?? $currentThoughtSignature) : null;

                                // Store complete tool call with thought signature
                                $toolCallsSummary[$toolCallId] = new \LarAgent\ToolCall(
                                    $toolCallId,
                                    $functionCall['name'] ?? '',
                                    json_encode($functionCall['args'] ?? []),
                                    $thoughtSignature
                                );
                            }
                            // Handle text content
                            elseif (isset($part['text'])) {
                                $delta = $part['text'];
                                $streamedMessage->appendContent($delta);

                                // Store thought signature in extras for text responses
                                if ($currentThoughtSignature !== null) {
                                    $streamedMessage->setExtra('thought_signature', $currentThoughtSignature);
                                }

                                // Execute callback if provided
                                if ($callback) {
                                    $callback($streamedMessage);
                                }

                                // Yield the streamed message
                                yield $streamedMessage;
                            }
                        }
                    } else {
                        // No parts in this chunk, reset last chunk
                        $streamedMessage->resetLastChunk();
                    }
                }
            }

            // Store the last response for getLastResponse()
            if ($lastResponseData) {
                $this->lastResponse = $lastResponseData;
            }

            // Set usage information if available (use formatter)
            if ($lastResponseData) {
                $usageData = $this->formatter->extractUsage($lastResponseData);
                if (! empty($usageData)) {
                    $streamedMessage->setUsage(Usage::fromArray($usageData));
                }

                // Extract thought signature for final text response
                $thoughtSignature = $this->formatter->extractThoughtSignature($lastResponseData);
                if ($thoughtSignature !== null && empty($toolCallsSummary)) {
                    $streamedMessage->setExtra('thought_signature', $thoughtSignature);
                }
            }

            // If we have tool calls, return a ToolCallMessage
            if (! empty($toolCallsSummary) && ($finishReason !== 'stop' || $finishReason === null)) {
                $toolCallObjects = array_values($toolCallsSummary);

                $toolCallMessage = new ToolCallMessage($toolCallObjects);

                // Transfer usage from streamed message if available
                if ($streamedMessage->getUsage() !== null) {
                    $toolCallMessage->setUsage($streamedMessage->getUsage());
                }

                // Execute callback if provided
                if ($callback) {
                    $callback($toolCallMessage);
                }

                yield $toolCallMessage;
            } else {
                // Mark the message as complete and yield final version
                $streamedMessage->setComplete(true);

                // Execute callback if provided
                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;
            }

        } catch (RequestException $e) {
            throw new RuntimeException('Gemini streaming API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Prepare the payload for API request.
     */
    protected function preparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        // Merge driver config with override settings
        $overrideConfig = DriverConfig::wrap($overrideSettings);
        $config = $this->getDriverConfig()->merge($overrideConfig);

        // Use formatter to convert Message objects to Gemini format
        $contents = $this->formatter->formatMessages($messages);

        // Extract system instructions separately (Gemini-specific)
        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        $payload = ['contents' => $contents];

        // System instructions
        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        // Generation config with known properties
        $generationConfig = [];

        if ($config->has('temperature')) {
            $generationConfig['temperature'] = $config->temperature;
        }
        if ($config->has('maxCompletionTokens')) {
            $generationConfig['maxOutputTokens'] = $config->maxCompletionTokens;
        }
        if ($config->has('topP')) {
            $generationConfig['topP'] = $config->topP;
        }

        // Gemini-specific extras (top_k, etc.)
        if ($config->getExtra('top_k') !== null) {
            $generationConfig['topK'] = $config->getExtra('top_k');
        }

        // Structured output support
        if ($this->structuredOutputEnabled()) {
            $generationConfig['responseJsonSchema'] = $this->unwrapResponseSchema($this->getResponseSchema());
            $generationConfig['responseMimeType'] = 'application/json';
        } elseif ($config->getExtra('response_schema') !== null) {
            // Fallback to config if response schema is passed via settings
            $generationConfig['responseJsonSchema'] = $this->unwrapResponseSchema($config->getExtra('response_schema'));
            $generationConfig['responseMimeType'] = 'application/json';
        }

        if (! empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // Tools support - use formatter for consistent tool formatting
        if (! empty($this->tools)) {
            $payload['tools'] = $this->formatter->formatTools(array_values($this->tools));
        }

        return $payload;
    }

    /**
     * Map LarAgent roles to Gemini roles.
     */
    protected function mapRoleToGeminiRole(string $role): string
    {
        return match ($role) {
            'user' => 'user',
            'assistant' => 'model',
            default => 'user',
        };
    }

    /**
     * @deprecated Use GeminiMessageFormatter::extractUsage() instead.
     *             This method is maintained for backwards compatibility.
     */
    protected function extractUsage(array $responseData): array
    {
        return $this->formatter->extractUsage($responseData);
    }

    /**
     * Format a tool for the Gemini API payload - CORRECTED parameter type.
     */
    public function formatToolForPayload($tool): array
    {
        $toolSchema = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ];

        if (! empty($tool->getProperties())) {
            $toolSchema['parameters'] = [
                'type' => 'object', // CORRECTED: Gemini API requires 'object' in lowercase; previous value was 'OBJECT' (uppercase), which is accepted by some other APIs (e.g., OpenAI) but not Gemini.
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }

    /**
     * @deprecated Use GeminiMessageFormatter::formatToolResultMessage() instead.
     *             This method is maintained for backwards compatibility.
     */
    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        $responseContent = is_string($result) ? $result : json_encode($result);

        return [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => $toolCall->getToolName(),
                        'response' => [
                            'name' => $toolCall->getToolName(),
                            'content' => $responseContent,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @deprecated Use GeminiMessageFormatter::formatToolCallMessage() instead.
     *             This method is maintained for backwards compatibility.
     */
    public function toolCallsToMessage(array $toolCalls): array
    {
        $toolCallsArray = [];
        foreach ($toolCalls as $tc) {
            $toolCallsArray[] = [
                'functionCall' => [
                    'name' => $tc->getToolName(),
                    'args' => json_decode($tc->getArguments(), true),
                ],
            ];
        }

        return [
            'role' => 'assistant',  // Use 'assistant' instead of 'model' for compatibility
            'parts' => $toolCallsArray,
        ];
    }

    /**
     * Unwrap a schema that might be in OpenAI format (with name, schema, strict).
     * Gemini expects just the raw JSON schema.
     *
     * @param  array  $schema  The schema to unwrap
     * @return array The raw JSON schema
     */
    protected function unwrapResponseSchema(array $schema): array
    {
        // If wrapped in OpenAI format, extract the inner schema
        if (isset($schema['schema']) && is_array($schema['schema'])) {
            return $schema['schema'];
        }

        // Already a raw schema
        return $schema;
    }

    /**
     * Get the last raw response from the API.
     */
    public function getLastResponse(): ?array
    {
        return is_array($this->lastResponse) ? $this->lastResponse : null;
    }
}
