<?php

namespace LarAgent\Drivers\Anthropic;

use Anthropic;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\MessageFormatter;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

class ClaudeDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    protected string $default_url = 'api.anthropic.com/v1';

    protected MessageFormatter $formatter;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        $apiKey = $this->getDriverConfig()->apiKey;
        $apiUrl = $this->getDriverConfig()->apiUrl ?? $this->default_url;
        if ($apiKey) {
            $this->client = $this->buildClient($apiKey, $apiUrl);
        } else {
            throw new \Exception('API key is required to use the Claude driver.');
        }
        $this->formatter = $this->createFormatter();
    }

    /**
     * Create the message formatter for this driver.
     */
    protected function createFormatter(): MessageFormatter
    {
        return new ClaudeMessageFormatter();
    }

    /**
     * Get the message formatter.
     */
    public function getFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    protected function buildClient(string $apiKey, string $baseUrl): mixed
    {
        $client = Anthropic::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpHeader('anthropic-version', '2023-06-01')
            ->withHttpClient($httpClient = new \GuzzleHttp\Client([]))
            ->make();

        return $client;
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Claude driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);

        $response = $this->client->messages()->create($payload);
        $this->lastResponse = $response;

        // Convert response object to array for formatter
        $responseArray = $response->toArray();

        // Use formatter extraction methods
        $finishReason = $this->formatter->extractFinishReason($responseArray);
        $metaData = ['usage' => $this->formatter->extractUsage($responseArray)];

        if ($finishReason === 'tool_calls') {
            $toolCalls = $this->formatter->extractToolCalls($responseArray);

            return new ToolCallMessage($toolCalls, $metaData);
        }

        if ($finishReason === 'stop') {
            $content = $this->formatter->extractContent($responseArray);

            return new AssistantMessage($content, $metaData);
        }

        throw new \Exception('Unexpected stop reason: '.$response->stop_reason);
    }

    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Claude driver.');
        }

        $payload = $this->preparePayload($messages, $overrideSettings);
        $payload['stream'] = true;

        $response = $this->client->messages()->createStreamed($payload);
        $streamedMessage = new StreamedAssistantMessage;

        $toolCalls = [];
        $pendingToolInputs = []; // id => partial JSON
        $pendingToolNames = []; // id => tool name
        $currentToolBlockIds = []; // index => tool use id

        $firstUsage = null; // first snapshot
        $finalUsage = null; // final snapshot
        $usageTimeline = []; // list of snapshots for each message type

        foreach ($response as $chunk) {
            $this->lastResponse = $chunk;

            $type = $chunk->type ?? null;

            // Message start
            if ($type === 'message_start') {
                $messageStartUsage = $chunk->usage?->toArray();
                if ($messageStartUsage) {
                    // create earliest usage
                    $firstUsage = $firstUsage ?? $messageStartUsage;
                    // create earliest usage timeline
                    $usageTimeline[] = ['event' => 'message_start', 'usage' => $messageStartUsage];

                    $streamedMessage->setUsage($messageStartUsage);
                }

                continue;
            }

            // Tool start
            if ($type === 'content_block_start') {
                $block = $chunk->content_block_start ?? $chunk->content_block ?? null;
                if (($block->type ?? '') === 'tool_use') {
                    $id = $block->id ?? 'tool_call_'.uniqid();
                    $pendingToolNames[$id] = $block->name ?? '';
                    $pendingToolInputs[$id] = '';
                    $currentToolBlockIds[$chunk->index] = $id;
                }

                continue;
            }

            // Text or tool input delta
            if ($type === 'content_block_delta') {
                $delta = $chunk->delta ?? null;
                $deltaType = $delta->type ?? '';

                if ($deltaType === 'text_delta') {
                    $text = $delta->text ?? '';
                    if ($text !== '') {
                        $streamedMessage->appendContent($text);

                        if ($callback) {
                            $callback($streamedMessage);
                        }
                        yield $streamedMessage;
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $id = $chunk->content_block->id ?? ($currentToolBlockIds[$chunk->index] ?? null);

                    if ($id !== null) {
                        // Append partial_json
                        $pendingToolInputs[$id] = ($pendingToolInputs[$id] ?? '').($delta->partial_json ?? '');
                    }
                } else {
                    // No recognized delta type, reset last chunk
                    $streamedMessage->resetLastChunk();
                }

                continue;
            }

            // Tool stop and finalize
            if ($type === 'content_block_stop') {
                $block = $chunk->content_block_stop ?? $chunk->content_block ?? null;
                if (($block->type ?? '') === 'tool_use') {
                    $id = $block->id ?? ($currentToolBlockIds[$chunk->index] ?? null);
                    if ($id !== null) {
                        $name = $pendingToolNames[$id] ?? '';
                        $args = $pendingToolInputs[$id] ?? '{}';

                        $toolCalls[] = new ToolCall($id, $name, $args);

                        // unset pending maps
                        unset($pendingToolNames[$id], $pendingToolInputs[$id]);

                        // clear the index map for this block
                        if (isset($chunk->index)) {
                            unset($currentToolBlockIds[$chunk->index]);
                        }
                    }
                }

                continue;
            }

            // End of message deltas
            if ($type === 'message_delta') {
                $stopReason = $chunk->delta->stop_reason ?? null;

                if (isset($chunk->usage)) {
                    $messageDeltaUsage = $chunk->usage?->toArray();
                    if ($messageDeltaUsage) {
                        $usageTimeline[] = ['event' => 'message_delta', 'usage' => $messageDeltaUsage];
                    }
                }

                if ($stopReason === 'tool_use') {
                    // Tool finalize
                    if (! empty($pendingToolInputs)) {
                        foreach ($pendingToolInputs as $id => $args) {
                            $name = $pendingToolNames[$id] ?? '';
                            $args = $args ?? '{}';
                            $toolCalls[] = new ToolCall($id, $name, $args);
                        }
                        $pendingToolInputs = [];
                        $pendingToolNames = [];
                    }

                    $finalUsage = $chunk->usage?->toArray() ?? $finalUsage;
                    $merged = $this->mergeUsageSnapshots($firstUsage, $finalUsage);

                    $toolCallMessage = new ToolCallMessage(
                        $toolCalls,
                        [
                            'usage' => $merged,
                            'usage_timeline' => $usageTimeline,
                        ]
                    );

                    if ($callback) {
                        $callback($toolCallMessage);
                    }
                    yield $toolCallMessage;

                    return;
                }

                if ($stopReason === 'end_turn') {
                    $finalUsage = $chunk->usage?->toArray() ?? $finalUsage;
                    // set the final usage on the streamed text message
                    $merged = $this->mergeUsageSnapshots($firstUsage, $finalUsage);
                    $streamedMessage->setUsage($merged);
                    break;
                }
            }

            if ($type === 'message_stop') {
                break;
            }
        }

        // Finalize the stream: attach merged usage, trigger callback,
        // mark the message as complete, and yield the final message.
        $merged = $this->mergeUsageSnapshots($firstUsage, $finalUsage);
        $streamedMessage->setUsage($merged);

        if ($callback) {
            $callback($streamedMessage);
        }

        $streamedMessage->setComplete(true);

        yield $streamedMessage;

    }

    protected function preparePayload(array $messages, DriverConfig|array $overrideSettings = []): array
    {
        if ($this->structuredOutputEnabled()) {
            throw new \Exception('Anthropic/Claude driver does not support structured output through JSON schema.');
        }

        // Merge driver config with override settings
        $overrideConfig = DriverConfig::wrap($overrideSettings);
        $config = $this->getDriverConfig()->merge($overrideConfig);

        // Use formatter to extract system instruction
        $systemPrompt = $this->formatter->extractSystemInstruction($messages);
        
        // Use formatter to convert Message objects to Claude format
        $chatMessages = $this->formatter->formatMessages($messages);

        // Build payload with known properties
        $payload = [
            'model' => $config->model ?? 'claude-3-7-sonnet-latest',
            'messages' => $chatMessages,
            'max_tokens' => $config->maxCompletionTokens ?? 1024,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        // Add optional known properties
        if ($config->has('temperature')) {
            $payload['temperature'] = $config->temperature;
        }
        if ($config->has('topP')) {
            $payload['top_p'] = $config->topP;
        }

        // Add any extra/custom settings (Claude-specific options)
        foreach ($config->getExtras() as $key => $value) {
            $payload[$key] = $value;
        }

        if (! empty($this->tools)) {
            $payload['tools'] = $this->formatter->formatTools(array_values($this->tools));
        }

        return $payload;
    }

    public function formatToolForPayload(ToolInterface $tool): array
    {
        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'input_schema' => [
                'type' => 'object',
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ],
        ];
    }

    /**
     * @deprecated Use ClaudeMessageFormatter::formatToolCallMessage() instead.
     *             This method is maintained for backwards compatibility.
     */
    public function toolCallsToMessage(array $toolCalls): array
    {
        $content = [];

        foreach ($toolCalls as $toolCall) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall->getId(),
                'name' => $toolCall->getToolName(),
                'input' => json_decode($toolCall->getArguments(), true),
            ];
        }

        return [
            'role' => 'assistant',
            'content' => $content,
        ];
    }

    /**
     * @deprecated Use ClaudeMessageFormatter::formatToolResultMessage() instead.
     *             This method is maintained for backwards compatibility.
     */
    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        return [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall->getId(),
                    'content' => is_string($result) ? $result : json_encode($result),
                ],
            ],
        ];
    }

    public function formatImagesForPayload(?array $images = null): array
    {
        $formattedImages = [];

        foreach ($images as $url) {
            $formattedImages[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $url,
                ],
            ];
        }

        return $formattedImages;
    }

    private function mergeUsageSnapshots(?array $first, ?array $final): array
    {
        $first = $first ?? [];
        $final = $final ?? [];

        // Input tokens: the first snapshot already represents the full prompt
        // (final often omits it or repeats same value). Prefer first.
        $input = $first['input_tokens'] ?? $final['input_tokens'] ?? null;

        // Output tokens: final is the total at the end, not a delta.
        // Prefer final, else fall back to first.
        $output = $final['output_tokens'] ?? $first['output_tokens'] ?? null;

        // Sanity: if both exist and "first > final", pick the max to avoid weird regressions.
        if (isset($first['output_tokens'], $final['output_tokens'])) {
            $output = max($first['output_tokens'], $final['output_tokens']);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => ($input ?? 0) + ($output ?? 0),
        ];
    }
}
