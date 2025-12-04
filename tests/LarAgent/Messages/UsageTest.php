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

    expect($array)->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_tokens' => 150,
    ]);
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
    expect($usage->toArray())->toBe([
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ]);
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

    expect($json)->toBe('{"prompt_tokens":100,"completion_tokens":50,"total_tokens":150}');
});
