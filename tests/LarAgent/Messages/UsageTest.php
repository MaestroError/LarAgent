<?php

use LarAgent\Usage\DataModels\Usage;

test('Usage: Creates from constructor with defaults', function () {
    $usage = new Usage;

    expect($usage->promptTokens)->toBe(0);
    expect($usage->completionTokens)->toBe(0);
    expect($usage->totalTokens)->toBe(0);
});

test('Usage: Creates from constructor with values', function () {
    $usage = new Usage(100, 50, 150);

    expect($usage->promptTokens)->toBe(100);
    expect($usage->completionTokens)->toBe(50);
    expect($usage->totalTokens)->toBe(150);
});

test('Usage: Auto-calculates total when not provided', function () {
    $usage = new Usage(100, 50);

    expect($usage->promptTokens)->toBe(100);
    expect($usage->completionTokens)->toBe(50);
    expect($usage->totalTokens)->toBe(150); // auto-calculated
});

test('Usage: Serializes to array correctly', function () {
    $usage = new Usage(100, 50, 150);
    $array = $usage->toArray();

    // Should include all fields (metadata will be null)
    expect($array['prompt_tokens'])->toBe(100);
    expect($array['completion_tokens'])->toBe(50);
    expect($array['total_tokens'])->toBe(150);
    expect($array)->toHaveKey('user_id');
    expect($array)->toHaveKey('created_at');
});

test('Usage: Creates from array with normalized keys', function () {
    $data = [
        'prompt_tokens' => 200,
        'completion_tokens' => 100,
        'total_tokens' => 300,
    ];

    $usage = Usage::fromArray($data);

    expect($usage->promptTokens)->toBe(200);
    expect($usage->completionTokens)->toBe(100);
    expect($usage->totalTokens)->toBe(300);
});

test('Usage: Auto-calculates total in fromArray when not provided', function () {
    $data = [
        'prompt_tokens' => 150,
        'completion_tokens' => 75,
    ];

    $usage = Usage::fromArray($data);

    expect($usage->promptTokens)->toBe(150);
    expect($usage->completionTokens)->toBe(75);
    expect($usage->totalTokens)->toBe(225); // auto-calculated
});

test('Usage: Handles zero values', function () {
    $usage = new Usage(0, 0, 0);

    expect($usage->promptTokens)->toBe(0);
    expect($usage->completionTokens)->toBe(0);
    expect($usage->totalTokens)->toBe(0);
    
    $array = $usage->toArray();
    expect($array['prompt_tokens'])->toBe(0);
    expect($array['completion_tokens'])->toBe(0);
    expect($array['total_tokens'])->toBe(0);
});

test('Usage: Handles missing values in array', function () {
    $data = [];

    $usage = Usage::fromArray($data);

    expect($usage->promptTokens)->toBe(0);
    expect($usage->completionTokens)->toBe(0);
    expect($usage->totalTokens)->toBe(0);
});

test('Usage: JSON serializes correctly', function () {
    $usage = new Usage(100, 50, 150);
    $json = json_encode($usage);
    $decoded = json_decode($json, true);

    // Check key fields are present
    expect($decoded['prompt_tokens'])->toBe(100);
    expect($decoded['completion_tokens'])->toBe(50);
    expect($decoded['total_tokens'])->toBe(150);
    expect($decoded)->toHaveKey('user_id');
    expect($decoded)->toHaveKey('created_at');
});

test('Usage: Creates with metadata fields', function () {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        totalTokens: 150,
        userId: 'user123',
        group: 'group1',
        chatName: 'chat-session',
        createdAt: '2024-01-01T00:00:00+00:00',
        model: 'gpt-4',
        provider: 'openai',
        agent: 'TestAgent'
    );

    expect($usage->promptTokens)->toBe(100);
    expect($usage->completionTokens)->toBe(50);
    expect($usage->userId)->toBe('user123');
    expect($usage->group)->toBe('group1');
    expect($usage->chatName)->toBe('chat-session');
    expect($usage->createdAt)->toBe('2024-01-01T00:00:00+00:00');
    expect($usage->model)->toBe('gpt-4');
    expect($usage->provider)->toBe('openai');
    expect($usage->agent)->toBe('TestAgent');
});

test('Usage: toArray includes metadata', function () {
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        totalTokens: 150,
        userId: 'user123',
        model: 'gpt-4',
        provider: 'openai'
    );

    $array = $usage->toArray();

    expect($array['prompt_tokens'])->toBe(100);
    expect($array['completion_tokens'])->toBe(50);
    expect($array['total_tokens'])->toBe(150);
    expect($array['user_id'])->toBe('user123');
    expect($array['model'])->toBe('gpt-4');
    expect($array['provider'])->toBe('openai');
});

test('Usage: fromArray with metadata', function () {
    $data = [
        'prompt_tokens' => 200,
        'completion_tokens' => 100,
        'total_tokens' => 300,
        'user_id' => 'user456',
        'group' => 'group2',
        'chat_name' => 'session1',
        'created_at' => '2024-01-15T10:00:00+00:00',
        'model' => 'gpt-3.5',
        'provider' => 'openai',
        'agent' => 'MyAgent',
    ];

    $usage = Usage::fromArray($data);

    expect($usage->promptTokens)->toBe(200);
    expect($usage->completionTokens)->toBe(100);
    expect($usage->totalTokens)->toBe(300);
    expect($usage->userId)->toBe('user456');
    expect($usage->group)->toBe('group2');
    expect($usage->chatName)->toBe('session1');
    expect($usage->createdAt)->toBe('2024-01-15T10:00:00+00:00');
    expect($usage->model)->toBe('gpt-3.5');
    expect($usage->provider)->toBe('openai');
    expect($usage->agent)->toBe('MyAgent');
});

test('Usage: auto-generates createdAt if not provided', function () {
    $usage = new Usage(100, 50);

    expect($usage->createdAt)->not->toBeNull();
    expect($usage->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('Usage: handles null metadata fields', function () {
    $usage = new Usage(100, 50, 150);

    expect($usage->userId)->toBeNull();
    expect($usage->group)->toBeNull();
    expect($usage->chatName)->toBeNull();
    expect($usage->model)->toBeNull();
    expect($usage->provider)->toBeNull();
    expect($usage->agent)->toBeNull();
});
