<?php

namespace LarAgent\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LarAgent\API\completions\CompletionRequestDTO;

class Completions
{
    public static function make(Request $request, string $agentClass): CompletionRequestDTO
    {
        return static::validateRequest($request);
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
}

