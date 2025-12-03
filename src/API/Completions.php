<?php

namespace LarAgent\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LarAgent\Agent;
use LarAgent\API\Completion\CompletionRequestDTO;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\PhantomTool;

class Completions
{
    protected CompletionRequestDTO $completion;

    protected Agent $agent;

    protected bool $stream = false;

    protected ?string $key = null;

    public static function make(Request $request, string $agentClass, ?string $model = null, ?string $key = null): array|\Generator
    {
        $completion = static::validateRequest($request);

        $instance = new self;
        $instance->completion = $completion;
        $instance->stream = $instance->completion->stream;
        if ($model !== null) {
            $instance->completion->model = $model;
        }
        if ($key) {
            $instance->key = $key;
        }

        $response = $instance->runAgent($agentClass);

        if ($response instanceof \Generator) {
            return $instance->streamChunks($response);
        }

        if ($response instanceof MessageInterface) {
            $message = $response->toArrayWithMeta();
            // Get usage from message directly (first-class property) or fall back to metadata (legacy)
            $usage = $message['usage'] ?? $message['metadata']['usage'] ?? null;
            unset($message['usage']);
            unset($message['metadata']['usage']);

            $choices = [[
                'index' => 0,
                'message' => $message,
                'logprobs' => null,
                'finish_reason' => $response instanceof ToolCallMessage ? 'tool_calls' : 'stop',
            ]];
        } else {
            throw new \InvalidArgumentException('Response is not a MessageInterface instance');
        }

        return [
            'id' => $instance->agent->getChatSessionId(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $instance->agent->model(),
            'choices' => $choices,
            'usage' => $usage,
        ];

    }

    private static function validateRequest(Request $request): CompletionRequestDTO
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'messages' => ['required', 'array'],
            'messages.*' => ['array'],
            'messages.*.role' => ['required'],
            'messages.*.content' => ['nullable'],
            'messages.*.tool_calls' => ['nullable'],
            'messages.*.tool_call_id' => ['nullable'],
            'model' => ['required', 'string'],
            'modalities' => ['nullable', 'array'],
            'modalities.*' => ['string'],
            'audio' => ['nullable', 'array'],
            'audio.format' => ['required_with:audio', 'in:wav,mp3,flac,opus,pcm16'],
            'audio.voice' => ['required_with:audio', 'in:alloy,ash,ballad,coral,echo,fable,nova,onyx,sage,shimmer'],
            'n' => ['nullable', 'integer'],
            'temperature' => ['nullable', 'numeric'],
            'top_p' => ['nullable', 'numeric'],
            'frequency_penalty' => ['nullable', 'numeric'],
            'presence_penalty' => ['nullable', 'numeric'],
            'max_completion_tokens' => ['nullable', 'integer'],
            'response_format' => ['nullable', 'array'],
            'response_format.type' => ['required_with:response_format', 'in:json_schema,json_object'],
            'response_format.json_schema' => ['required_if:response_format.type,json_schema', 'array'],
            'tools' => ['nullable', 'array'],
            'tool_choice' => ['nullable'],
            'parallel_tool_calls' => ['nullable', 'boolean'],
            'stream' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($data) {
            if (isset($data['modalities']) && \is_array($data['modalities']) && \in_array('audio', $data['modalities'], true)) {
                if (empty($data['audio']) || ! \is_array($data['audio'])) {
                    $validator->errors()->add('audio', 'The audio field is required when requesting audio.');
                }
            }
        });

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $validated = $validator->validated();

        return CompletionRequestDTO::fromArray($validated);
    }

    protected function runAgent(string $agentClass)
    {
        if ($this->key) {
            $key = $this->key;
        } else {
            $key = Str::random(10);
        }
        $this->agent = new $agentClass($key);
        $this->agent->returnMessage();

        $messages = $this->completion->messages;
        if (! empty($messages)) {
            $last = array_pop($messages);
            $messageArray = new MessageArray($messages);
            foreach ($messageArray->all() as $message) {
                $this->agent->addMessage($message);
            }

            $lastArray = new MessageArray([$last]);
            $this->agent->message($lastArray->all()[0]);
        }

        if ($this->completion->model) {
            $this->agent->withModel($this->completion->model);
        }

        if ($this->completion->temperature !== null) {
            $this->agent->temperature($this->completion->temperature);
        }
        if ($this->completion->n !== null) {
            $this->agent->n($this->completion->n);
        }
        if ($this->completion->top_p !== null) {
            $this->agent->topP($this->completion->top_p);
        }
        if ($this->completion->frequency_penalty !== null) {
            $this->agent->frequencyPenalty($this->completion->frequency_penalty);
        }
        if ($this->completion->presence_penalty !== null) {
            $this->agent->presencePenalty($this->completion->presence_penalty);
        }
        if ($this->completion->max_completion_tokens !== null) {
            $this->agent->maxCompletionTokens($this->completion->max_completion_tokens);
        }

        $this->registerResponseSchema();

        // @todo Pass modalities and audio options to agent

        // Register tools from payload
        $this->registerPhantomTools();

        $this->registerToolChoice();

        // Parallel tool calls is disabled in via this API, `false` and `null` are only values to accept
        if ($this->completion->parallel_tool_calls !== true) {
            $this->agent->parallelToolCalls($this->completion->parallel_tool_calls);
        }

        if ($this->stream) {
            return $this->agent->respondStreamed();
        }

        return $this->agent->respond();
    }

    protected function registerResponseSchema()
    {
        if ($this->completion->response_format !== null) {
            if (($this->completion->response_format['type'] ?? null) === 'json_schema') {
                $schema = $this->completion->response_format['json_schema'] ?? null;
                if (is_string($schema)) {
                    $decoded = json_decode($schema, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $schema = $decoded;
                    }
                }
                if (is_array($schema)) {
                    $this->agent->responseSchema($schema);
                }
            }
            // @todo handle json_object type
        }
    }

    protected function registerToolChoice()
    {
        if ($this->completion->tool_choice !== null) {
            $choice = $this->completion->tool_choice;
            if ($choice === 'auto') {
                $this->agent->toolAuto();
            } elseif ($choice === 'none') {
                $this->agent->toolNone();
            } elseif ($choice === 'required') {
                $this->agent->toolRequired();
            } elseif (is_array($choice) && isset($choice['function']['name'])) {
                $this->agent->forceTool($choice['function']['name']);
            }
        }
    }

    protected function registerPhantomTools()
    {
        if (isset($this->completion->tools) && is_array($this->completion->tools)) {
            foreach ($this->completion->tools as $tool) {
                if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function'])) {
                    $function = $tool['function'];
                    $name = $function['name'] ?? null;
                    $description = $function['description'] ?? '';

                    if ($name) {
                        $phantomTool = PhantomTool::create($name, $description)
                            ->setCallback([self::class, 'phantomToolCallback']);

                        // Add properties
                        if (isset($function['parameters']) && isset($function['parameters']['properties'])) {
                            foreach ($function['parameters']['properties'] as $propName => $propDetails) {
                                $type = $propDetails['type'] ?? 'string';
                                $propDescription = $propDetails['description'] ?? '';
                                $enum = $propDetails['enum'] ?? [];

                                $phantomTool->addProperty($propName, $type, $propDescription, $enum);
                            }
                        }

                        // Set required properties
                        if (isset($function['parameters']['required']) && is_array($function['parameters']['required'])) {
                            foreach ($function['parameters']['required'] as $requiredProp) {
                                $phantomTool->setRequired($requiredProp);
                            }
                        }

                        // Register the tool with the agent
                        $this->agent->withTool($phantomTool);
                    }
                }
            }
        }
    }

    protected function streamChunks(\Generator $stream): \Generator
    {
        foreach ($stream as $chunk) {
            if ($chunk instanceof StreamedAssistantMessage) {
                // Get usage from message directly (first-class property) or fall back to metadata (legacy)
                $message = $chunk->toArrayWithMeta();
                $usage = $message['usage'] ?? $message['metadata']['usage'] ?? null;

                yield [
                    'id' => $this->agent->getChatSessionId(),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $this->agent->model(),
                    'choices' => [[
                        'index' => 0,
                        'delta' => [
                            'role' => 'assistant',
                            'content' => $chunk->getLastChunk(),
                        ],
                        'logprobs' => null,
                        'finish_reason' => $chunk->isComplete() ? 'stop' : null,
                    ]],
                    'usage' => $usage,
                ];
            } elseif ($chunk instanceof ToolCallMessage) {
                // Get usage from message directly (first-class property) or fall back to metadata (legacy)
                $message = $chunk->toArrayWithMeta();
                $usage = $message['usage'] ?? $message['metadata']['usage'] ?? null;
                yield [
                    'id' => $this->agent->getChatSessionId(),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $this->agent->model(),
                    'choices' => [[
                        'index' => 0,
                        'delta' => [
                            'role' => 'tool_calls',
                            'tool_calls' => $chunk->toArrayWithMeta()['tool_calls'] ?? [],
                        ],
                        'logprobs' => null,
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => $usage,
                ];
            } elseif (is_array($chunk)) {
                yield $chunk;
            }
        }
    }

    public static function phantomToolCallback(...$args)
    {
        // return 'Phantom tool called with arguments: ' . json_encode($args);
    }
}
