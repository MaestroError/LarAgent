<?php

namespace LarAgent\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LarAgent\API\completions\CompletionRequestDTO;
use LarAgent\Agent;
use LarAgent\PseudoTool;
use LarAgent\Message;

class Completions
{
    protected CompletionRequestDTO $completion;

    protected Agent $agent;
    public static function make(Request $request, string $agentClass): array
    {
        $completion = static::validateRequest($request);

        $instance = new self();
        $instance->completion = $completion;

        $response = $instance->runAgent($agentClass);

        if ($response instanceof ToolCallMessage) {
            // @todo Return tool call
        } else {

            $content = (string) $response;

            return [
                'id' => $instance->agent->getChatSessionId(),
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $instance->agent->model(),
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                        'refusal' => null,
                        'annotations' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
            ];
        }


    }

    private static function validateRequest(Request $request): CompletionRequestDTO
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'messages' => ['required', 'array'],
            'messages.*' => ['array'],
            'messages.*.role' => ['required'],
            'messages.*.content' => ['required'],
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
        ]);

        $validator->after(function ($validator) use ($data) {
            if (isset($data['modalities']) && \is_array($data['modalities']) && \in_array('audio', $data['modalities'], true)) {
                if (empty($data['audio']) || ! \is_array($data['audio'])) {
                    $validator->errors()->add('audio', 'The audio field is required when requesting audio.');
                }
            }
        });

        $validated = $validator->validate();

        return CompletionRequestDTO::fromArray($validated);
    }

    protected function runAgent(string $agentClass)
    {
        $this->agent = new $agentClass(Str::random(10));

        $messages = $this->completion->messages;
        if (! empty($messages)) {
            $last = array_pop($messages);
            foreach ($messages as $message) {
                $this->agent->addMessage(Message::fromArray($message));
            }
            $this->agent->message($last['content']);
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
        $this->registerPseudoTools();

        $this->registerToolChoice();

        // Parallel tool calls is disabled in via this API, `false` and `null` are only values to accept
        if ($this->completion->parallel_tool_calls !== true) {
            $this->agent->parallelToolCalls($this->completion->parallel_tool_calls);
        }

        if ($this->completion['stream'] ?? false) {
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

    protected function registerPseudoTools()
    {
        if (isset($this->completion->tools) && is_array($this->completion->tools)) {
            foreach ($this->completion->tools as $tool) {
                if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function'])) {
                    $function = $tool['function'];
                    $name = $function['name'] ?? null;
                    $description = $function['description'] ?? '';
                    
                    if ($name) {
                        $pseudoTool = PseudoTool::create($name, $description)
                            ->setCallback([self::class, 'pseudoToolCallback']);
                        
                        // Add properties
                        if (isset($function['parameters']) && isset($function['parameters']['properties'])) {
                            foreach ($function['parameters']['properties'] as $propName => $propDetails) {
                                $type = $propDetails['type'] ?? 'string';
                                $propDescription = $propDetails['description'] ?? '';
                                $enum = $propDetails['enum'] ?? [];
                                
                                $pseudoTool->addProperty($propName, $type, $propDescription, $enum);
                            }
                        }
                        
                        // Set required properties
                        if (isset($function['parameters']['required']) && is_array($function['parameters']['required'])) {
                            foreach ($function['parameters']['required'] as $requiredProp) {
                                $pseudoTool->setRequired($requiredProp);
                            }
                        }
                        
                        // Register the tool with the agent
                        $this->agent->addTool($pseudoTool);
                    }
                }
            }
        }
    }

    public static function pseudoToolCallback(...$args)
    {
        // return 'Pseudo tool called with arguments: ' . json_encode($args);
    }
}
