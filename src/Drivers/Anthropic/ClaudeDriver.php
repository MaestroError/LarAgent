<?php

namespace LarAgent\Drivers\Anthropic;

use Anthropic;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

class ClaudeDriver extends LlmDriver implements LlmDriverInterface
{
    protected mixed $client;

    protected string $default_url = 'api.anthropic.com/v1';

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);
        if ($settings['api_key']) {
            $this->client = $this->buildClient($settings['api_key'], $settings['api_url'] ?? $this->default_url);
        } else {
            throw new \Exception('API key is required to use the Claude driver.');
        }
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

    public function sendMessage(array $messages, array $options = []): AssistantMessage
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Claude driver.');
        }

        $payload = $this->preparePayload($messages, $options);

        $response = $this->client->messages()->create($payload);
        $this->lastResponse = $response;

        $stopReason = $response->stop_reason;
        $metaData = ['usage' => $response->usage->toArray()];

        if ($stopReason === 'tool_use') {
            $toolCalls = [];

            foreach ($response->content as $item) {
                if (($item->type ?? null) === 'tool_use') {
                    $toolCalls[] = new ToolCall(
                        $item->id ?? '',
                        $item->name ?? '',
                        json_encode($item->input ?? [])
                    );
                }
            }

            $message = $this->toolCallsToMessage($toolCalls);

            $toolCallMessage = new ToolCallMessage(
                toolCalls: $toolCalls,
                message: $message,
                metadata: $metaData
            );

            return $toolCallMessage;
        }

        if ($stopReason === 'end_turn') {
            $content = $response->content[0]->text;

            $assistantMessage = new AssistantMessage($content, $metaData);

            return $assistantMessage;
        }

        throw new \Exception('Unexpected stop reason: '.$stopReason);
    }

    public function sendMessageStreamed(array $messages, array $options = [], ?callable $callback = null): \Generator
    {
        if (empty($this->client)) {
            throw new \Exception('API key is required to use the Claude driver.');
        }

        $payload = $this->preparePayload($messages, $options);
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

                    $message = $this->toolCallsToMessage($toolCalls);
                    $toolCallMessage = new ToolCallMessage(
                        toolCalls: $toolCalls,
                        message: $message,
                        metadata: [
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
                    $streamedMessage->setComplete(true);
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

    protected function preparePayload(array $messages, array $options = []): array
    {
        if ($this->structuredOutputEnabled()) {
            throw new \Exception('Anthropic/Claude driver does not support structured output through JSON schema.');
        }

        $payload = [];

        if (empty($options['model'])) {
            $options['model'] = $this->settings['model'] ?? 'claude-3-7-sonnet-latest';
        }

        $payload['model'] = $options['model'];

        $this->setConfig($options);

        $systemPrompt = null;
        $chatMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt = $message['content'];
            } else {
                $messageContent = [];

                foreach ($message['content'] as $item) {
                    // Format each image URL into Claude's expected format
                    if (isset($item['type'], $item['image_url']['url']) && $item['type'] === 'image_url') {
                        $messageContent[] = $this->formatImagesForPayload([$item['image_url']['url']])[0];
                    } else {
                        $messageContent[] = $item;
                    }
                }

                $chatMessages[] = [
                    'role' => $message['role'],
                    'content' => $messageContent,
                ];

            }
        }

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $payload['messages'] = $chatMessages;

        $payload['max_tokens'] = $options['max_completion_tokens'] ?? $this->settings['max_completion_tokens'] ?? 1024;

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (! empty($this->tools)) {
            foreach ($this->getRegisteredTools() as $tool) {
                $payload['tools'][] = $this->formatToolForPayload($tool);
            }
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
