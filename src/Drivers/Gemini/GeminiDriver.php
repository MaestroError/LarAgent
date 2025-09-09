<?php

namespace LarAgent\Drivers\Gemini;

use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Generator;

class GeminiDriver extends LlmDriver
{
    protected Client $httpClient;
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';
    protected mixed $lastResponse;
    protected array $config = [];

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);

        if (empty($settings['api_key'])) {
            throw new RuntimeException('Gemini driver requires an API key.');
        }

        $this->apiKey = $settings['api_key'];
        $this->config = $settings;

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => ['key' => $this->apiKey],
        ]);
    }

    /**
     * Send a message to the LLM and receive a response using native Gemini API.
     *
     * @param array $messages Array of messages to send
     * @param array $options Configuration options
     * @return AssistantMessage The response from the LLM
     *
     * @throws RuntimeException
     */
    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        try {
            // Prepare the payload for Gemini API
            $payload = $this->preparePayload($messages, $options);

            // Determine the model to use
            $model = $options['model'] ?? $this->config['model'] ?? 'gemini-pro';

            // Correct URL format: models/{model}:generateContent
            $url = "models/{$model}:generateContent";

            // Make the API request
            $response = $this->httpClient->post($url, ['json' => $payload]);
            $responseData = json_decode($response->getBody()->getContents(), true);

            $this->lastResponse = $responseData;

            // Handle the response based on finish reason
            if (isset($responseData['candidates'][0]['finishReason'])) {
                $finishReason = $responseData['candidates'][0]['finishReason'];

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

        } catch (RequestException $e) {
            throw new RuntimeException("Gemini API request failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Prepare the payload for API request with common settings.
     *
     * @param array $messages The messages to send
     * @param array $options Configuration options
     * @return array The prepared payload
     */
    protected function preparePayload(array $messages, array $options = []): array
    {
    // We collect system instructions separately
    $systemInstructions = [];
    $filteredMessages = [];

    foreach ($messages as $message) {
        $role = $message['role'];
        if ($role === 'system' || $role === 'developer') {
            // For system messages we use only text content
            if (isset($message['content']) && is_string($message['content'])) {
                $systemInstructions[] = $message['content'];
            }
        } elseif ($role !== 'tool') {
            $filteredMessages[] = $message;
        }
    }

    // Convert the remaining messages to the format Gemini
    $contents = [];
    foreach ($filteredMessages as $message) {
        $role = $this->mapRoleToGeminiRole($message['role']);
        $parts = [];

        // We process different message formats
        if (isset($message['content']) && is_string($message['content'])) {
            // Regular text message
            $parts[] = ['text' => $message['content']];
        } elseif (isset($message['parts']) && is_array($message['parts'])) {
            // Message with parts (for tools)
            $parts = $message['parts'];
        } elseif (isset($message['content']) && is_array($message['content'])) {
            // Check that parts is not empty
            foreach ($message['content'] as $contentPart) {
                if (isset($contentPart['text']) && is_string($contentPart['text'])) {
                    $parts[] = ['text' => $contentPart['text']];
                } elseif (isset($contentPart['type']) && $contentPart['type'] === 'text') {
                    $parts[] = ['text' => $contentPart['text'] ?? ''];
                }
            }
        }

        // Check that parts is not empty
        if (!empty($parts)) {
            $contents[] = [
                'role' => $role,
                'parts' => $parts
            ];
        }
    }

    $payload = ['contents' => $contents];

    // Add system instructions if any
    if (!empty($systemInstructions)) {
        $instructionText = implode("\n", $systemInstructions);
        $payload['systemInstruction'] = [
            'parts' => [
                ['text' => $instructionText]
            ]
        ];
    }

        // Add generation config
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

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // Add tools if any are registered
        if (!empty($this->tools)) {
            $payload['tools'] = [
                'functionDeclarations' => array_map(
                    fn($tool) => $this->formatToolForPayload($tool),
                    $this->tools
                )
            ];
        }

        return $payload;
    }

    /**
     * Map LarAgent roles to Gemini roles.
     *
     * @param string $role The LarAgent role
     * @return string The Gemini role
     */
    protected function mapRoleToGeminiRole(string $role): string
    {
        return match ($role) {
            'user' => 'user',
            'assistant' => 'model',
           // 'system' => 'user',  Gemini doesn't have system role, map to user
            default => 'user',
        };
    }

    /**
     * Extract usage information from Gemini response.
     *
     * @param array $responseData The response from Gemini API
     * @return array Usage information
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
     * Format a tool for the Gemini API payload.
     *
     * @param mixed $tool The tool instance
     * @return array Formatted tool for Gemini API
     */
    public function formatToolForPayload($tool): array
    {
        $toolSchema = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
        ];

        if (!empty($tool->getProperties())) {
            $toolSchema['parameters'] = [
                'type' => 'OBJECT',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }

        return $toolSchema;
    }

    /**
     * Convert a tool call result to a message format.
     *
     * @param ToolCallInterface $toolCall The tool call instance
     * @param mixed $result The result of the tool execution
     * @return array Message format for tool result
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
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Convert tool calls to a message format.
     *
     * @param array $toolCalls Array of tool calls
     * @return array Message format for tool calls
     */
    public function toolCallsToMessage(array $toolCalls): array
    {
        $toolCallsArray = [];
        foreach ($toolCalls as $tc) {
            $toolCallsArray[] = [
                'functionCall' => [
                    'name' => $tc->getToolName(),
                    'args' => json_decode($tc->getArguments(), true),
                ]
            ];
        }

        return [
            'role' => 'model',
            'parts' => $toolCallsArray,
        ];
    }

    /**
     * Send a message to the LLM and receive a streamed response.
     * Not implemented yet for native Gemini API.
     *
     * @param array $messages Array of messages to send
     * @param array $options Configuration options
     * @param callable|null $callback Optional callback function
     * @return Generator
     *
     * @throws RuntimeException
     */
    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): Generator
    {
        throw new RuntimeException('Streaming is not yet implemented for native Gemini API');
    }
}
