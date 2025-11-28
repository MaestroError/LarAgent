<?php

namespace LarAgent\Drivers\Gemini;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use RuntimeException;

class GeminiDriver extends LlmDriver
{
    protected Client $httpClient;

    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';

    protected array $config = [];

    protected MessageFormatter $formatter;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);

        if (empty($settings['api_key'])) {
            throw new RuntimeException('Gemini driver requires an API key.');
        }

        $this->apiKey = $settings['api_key'];
        $this->config = $settings;

        // Configurable base URL
        $this->baseUrl = $settings['api_url'] ?? $this->baseUrl;

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
    protected function createFormatter(): MessageFormatter
    {
        return new GeminiMessageFormatter();
    }

    /**
     * Get the message formatter.
     */
    public function getFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    /**
     * Send a message to the LLM and receive a response using native Gemini API.
     */
    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        try {
            $payload = $this->preparePayload($messages, $options);

            $model = $options['model'] ?? $this->config['model'] ?? 'gemini-1.5-flash-latest';

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
        // Use formatter's hasToolCalls since Gemini returns 'STOP' even with tool calls
        if ($this->formatter->hasToolCalls($responseData)) {
            $toolCalls = $this->formatter->extractToolCalls($responseData);
            $metaData = [
                'usage' => $this->formatter->extractUsage($responseData),
                'tool_calls' => $toolCalls,
            ];

            return new ToolCallMessage($toolCalls, $metaData);
        }

        $finishReason = $this->formatter->extractFinishReason($responseData);

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseData);
            $metaData = [
                'usage' => $this->formatter->extractUsage($responseData),
            ];

            return new AssistantMessage($content, $metaData);
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
    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): Generator
    {
        try {
            $payload = $this->preparePayload($messages, $options);

            $model = $options['model'] ?? $this->config['model'] ?? 'gemini-1.5-flash-latest';

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
                        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                            // Handle function calls
                            if (isset($part['functionCall'])) {
                                $functionCall = $part['functionCall'];
                                $toolCallId = 'tool_call_'.uniqid();

                                // Store complete tool call
                                $toolCallsSummary[$toolCallId] = new \LarAgent\ToolCall(
                                    $toolCallId,
                                    $functionCall['name'] ?? '',
                                    json_encode($functionCall['args'] ?? [])
                                );
                            }
                            // Handle text content
                            elseif (isset($part['text'])) {
                                $delta = $part['text'];
                                $streamedMessage->appendContent($delta);

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
                $usage = $this->formatter->extractUsage($lastResponseData);
                $streamedMessage->setUsage($usage);
            }

            // If we have tool calls, return a ToolCallMessage
            if (! empty($toolCallsSummary) && ($finishReason !== 'stop' || $finishReason === null)) {
                $toolCallObjects = array_values($toolCallsSummary);

                $toolCallMessage = new ToolCallMessage(
                    $toolCallObjects,
                    $streamedMessage->getUsage() ? ['usage' => $streamedMessage->getUsage()] : []
                );

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
    protected function preparePayload(array $messages, array $options = []): array
    {
        // Use formatter to convert Message objects to Gemini format
        $contents = $this->formatter->formatMessages($messages);
        
        // Extract system instructions separately (Gemini-specific)
        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        $payload = ['contents' => $contents];

        // System instructions
        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        // Generation config
        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = $options['max_tokens'];
        }
        if (isset($options['top_p'])) {
            $generationConfig['topP'] = $options['top_p'];
        }
        if (isset($options['top_k'])) {
            $generationConfig['topK'] = $options['top_k'];
        }

        // Structured output support
        if ($this->structuredOutputEnabled()) {
            $generationConfig['responseJsonSchema'] = $this->getResponseSchema();
            $generationConfig['responseMimeType'] = 'application/json';
        } elseif (isset($options['response_schema'])) {
            // Fallback to options if response schema is passed via options
            $generationConfig['responseJsonSchema'] = $options['response_schema'];
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
     * Get the last raw response from the API.
     */
    public function getLastResponse(): ?array
    {
        return is_array($this->lastResponse) ? $this->lastResponse : null;
    }
}
