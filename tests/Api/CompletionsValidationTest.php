<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LarAgent\API\Completions;
use LarAgent\API\completions\CompletionRequestDTO;

it('throws validation exception when messages field is missing', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
    ]);

    Completions::make($request, 'TestAgent');
})->throws(ValidationException::class);

it('throws validation exception when audio is requested but not provided', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'modalities' => ['audio'],
    ]);

    Completions::make($request, 'TestAgent');
})->throws(ValidationException::class);

it('validates a correct request', function () {
    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'modalities' => ['text', 'audio'],
        'audio' => ['format' => 'mp3', 'voice' => 'nova'],
    ]);

    $result = Completions::make($request, 'TestAgent');

    expect($result)->toBeInstanceOf(CompletionRequestDTO::class)
        ->and($result['model'])->toBe('gpt-4o');
});

