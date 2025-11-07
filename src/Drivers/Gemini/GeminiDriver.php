<?php

namespace LarAgent\Drivers\Gemini;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use RuntimeException;

class GeminiDriver extends LlmDriver
{
    protected Client $httpClient;

    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';

    protected array $config = [];

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
        if (isset($responseData['candidates'][0]['finishReason'])) {
            $finishReason = $responseData['candidates'][0]['finishReason'];

            // Check if there are function calls in the response, regardless of finish reason
            $toolCalls = $this->extractToolCalls($responseData);
            
            if (!empty($toolCalls)) {
                $message = $this->toolCallsToMessage($toolCalls);
                $metaData = [
                    'usage' => $this->extractUsage($responseData),
                    'tool_calls' => $toolCalls,
                ];

                return new ToolCallMessage($toolCalls, $message, $metaData);
            }

            if ($finishReason === 'STOP') {
                $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $metaData = [
                    'usage' => $this->extractUsage($responseData),
                ];

                return new AssistantMessage($content, $metaData);
            }

            if ($finishReason === 'RECITATION' || $finishReason === 'SAFETY') {
                throw new RuntimeException("Gemini API finished with reason: {$finishReason}");
            }
        }

        throw new RuntimeException('Unexpected response format from Gemini API');
    }

    /**
     * Extract tool calls from response data.
     */
    protected function extractToolCalls(array $responseData): array
    {
        $toolCalls = [];
        if (isset($responseData['candidates'][0]['content']['parts'])) {
            foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    // Create ToolCall objects instead of arrays
                    $toolCalls[] = new \LarAgent\ToolCall(
                        'tool_call_' . uniqid(), // Generate a unique ID
                        $part['functionCall']['name'] ?? '',
                        json_encode($part['functionCall']['args'] ?? [])
                    );
                }
            }
        }

        return $toolCalls;
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
            $accumulatedContent = '';

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

                    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                        $newContent = $responseData['candidates'][0]['content']['parts'][0]['text'];

                        // Only yield new content
                        if (strlen($newContent) > strlen($accumulatedContent)) {
                            $delta = substr($newContent, strlen($accumulatedContent));
                            $accumulatedContent = $newContent;

                            $message = new AssistantMessage($delta);
                            yield $message;

                            if ($callback) {
                                $callback($delta);
                            }
                        }
                    }
                }
            }

            // Yield final message with full content
            yield new AssistantMessage($accumulatedContent, [
                'usage' => $this->extractUsage($this->lastResponse ?? []),
                'complete' => true,
            ]);

        } catch (RequestException $e) {
            throw new RuntimeException('Gemini streaming API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Prepare the payload for API request.
     */
    protected function preparePayload(array $messages, array $options = []): array
    {
        $systemInstructions = [];
        $filteredMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            if ($role === 'system' || $role === 'developer') {
                if (isset($message['content']) && is_string($message['content'])) {
                    $systemInstructions[] = $message['content'];
                }
            } elseif ($role !== 'tool') {
                $filteredMessages[] = $message;
            }
        }

        $contents = [];
        foreach ($filteredMessages as $message) {
            $role = $this->mapRoleToGeminiRole($message['role']);
            $parts = [];

            // Check for parts first (for tool calls and tool results)
            if (isset($message['parts']) && is_array($message['parts'])) {
                $parts = $message['parts'];
            } elseif (isset($message['content']) && is_string($message['content']) && $message['content'] !== '') {
                $parts[] = ['text' => $message['content']];
            } elseif (isset($message['content']) && is_array($message['content'])) {
                foreach ($message['content'] as $contentPart) {
                    if (isset($contentPart['text']) && is_string($contentPart['text'])) {
                        $parts[] = ['text' => $contentPart['text']];
                    } elseif (isset($contentPart['type']) && $contentPart['type'] === 'text') {
                        $parts[] = ['text' => $contentPart['text'] ?? ''];
                    }
                }
            }

            if (! empty($parts)) {
                $contents[] = [
                    'role' => $role,
                    'parts' => $parts,
                ];
            }
        }

        $payload = ['contents' => $contents];

        // System instructions
        if (! empty($systemInstructions)) {
            $instructionText = implode("\n", $systemInstructions);
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $instructionText],
                ],
            ];
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
        if (isset($options['response_schema'])) {
            $generationConfig['responseJsonSchema'] = $options['response_schema'];
            $generationConfig['responseMimeType'] = "application/json";
        }

        if (! empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // Tools support - CORRECTED structure
        if (! empty($this->tools)) {
            $payload['tools'] = [
                [
                    'functionDeclarations' => array_map(
                        fn ($tool) => $this->formatToolForPayload($tool),
                        array_values($this->tools)
                    ),
                ],
            ];
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
     * Extract usage information from Gemini response.
     */
    protected function extractUsage(array $responseData): array
    {
        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        if (isset($responseData['usageMetadata'])) {
            $usage = [
                'prompt_tokens' => $responseData['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $responseData['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $responseData['usageMetadata']['totalTokenCount'] ?? 0,
            ];
        }

        return $usage;
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
     * Convert a tool call result to a message format.
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
     * Convert tool calls to a message format.
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
