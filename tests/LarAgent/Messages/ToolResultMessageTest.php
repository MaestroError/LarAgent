<?php

use LarAgent\Drivers\Gemini\GeminiMessageFormatter;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Messages\ToolResultMessage;

test('ToolResultMessage: Creates with tool name', function () {
    $message = new ToolResultMessage(
        'The weather is sunny',
        'call_123',
        'get_weather'
    );

    expect($message->getToolName())->toBe('get_weather');
    expect($message->getToolCallId())->toBe('call_123');
    expect($message->getContentAsString())->toBe('The weather is sunny');
});

test('ToolResultMessage: toArray includes tool_name at top level', function () {
    $message = new ToolResultMessage(
        'Result content',
        'call_456',
        'my_tool'
    );

    $array = $message->toArray();

    expect($array)->toHaveKey('tool_name');
    expect($array['tool_name'])->toBe('my_tool');
    expect($array['tool_call_id'])->toBe('call_456');
    expect($array['role'])->toBe('tool');
});

test('ToolResultMessage: fromArray restores tool_name from top level', function () {
    $data = [
        'role' => 'tool',
        'content' => 'Result content',
        'tool_call_id' => 'call_789',
        'tool_name' => 'restored_tool',
    ];

    $message = ToolResultMessage::fromArray($data);

    expect($message->getToolName())->toBe('restored_tool');
    expect($message->getToolCallId())->toBe('call_789');
});

test('ToolResultMessage: fromArray extracts tool_name from nested content (backward compatibility)', function () {
    // This simulates OLD stored data format before the fix
    $oldFormatData = [
        'role' => 'tool',
        'content' => [
            'content' => 'Weather is 22°C',
            'tool_call_id' => 'call_old',
            'tool_name' => 'get_weather',
        ],
        'tool_call_id' => 'call_old',
        // Note: NO tool_name at top level - this is the old format
    ];

    $message = ToolResultMessage::fromArray($oldFormatData);

    expect($message->getToolName())->toBe('get_weather');
    expect($message->getToolCallId())->toBe('call_old');
    expect($message->getContentAsString())->toBe('Weather is 22°C');
});

test('ToolResultMessage: round-trip preserves tool_name', function () {
    $original = new ToolResultMessage(
        'Test result',
        'call_roundtrip',
        'test_tool'
    );

    $serialized = $original->toArray();
    $restored = ToolResultMessage::fromArray($serialized);

    expect($restored->getToolName())->toBe('test_tool');
    expect($restored->getToolCallId())->toBe('call_roundtrip');
    expect($restored->getContentAsString())->toBe('Test result');
});

test('ToolResultMessage: MessageArray round-trip preserves tool_name', function () {
    $original = new ToolResultMessage(
        'Tool output',
        'call_array',
        'array_tool'
    );

    $messageArray = new MessageArray($original);
    $serialized = $messageArray->toArray();
    $restored = MessageArray::fromArray($serialized);

    $restoredMessage = $restored->first();

    expect($restoredMessage)->toBeInstanceOf(ToolResultMessage::class);
    expect($restoredMessage->getToolName())->toBe('array_tool');
});

test('ToolResultMessage: Gemini formatter outputs correct functionResponse.name', function () {
    $message = new ToolResultMessage(
        'Weather data',
        'call_gemini',
        'get_weather'
    );

    $formatter = new GeminiMessageFormatter;
    $formatted = $formatter->formatMessage($message);

    expect($formatted['parts'][0]['functionResponse']['name'])->toBe('get_weather');
    expect($formatted['parts'][0]['functionResponse']['response']['name'])->toBe('get_weather');
});

test('ToolResultMessage: Gemini formatter with restored message from old format', function () {
    // Simulate loading from old storage format
    $oldFormatData = [
        'role' => 'tool',
        'content' => [
            'content' => 'API response',
            'tool_call_id' => 'call_old_gemini',
            'tool_name' => 'api_call',
        ],
        'tool_call_id' => 'call_old_gemini',
    ];

    $message = ToolResultMessage::fromArray($oldFormatData);
    $formatter = new GeminiMessageFormatter;
    $formatted = $formatter->formatMessage($message);

    // This is the fix for Issue #131 - functionResponse.name should NOT be empty
    expect($formatted['parts'][0]['functionResponse']['name'])->toBe('api_call');
    expect($formatted['parts'][0]['functionResponse']['name'])->not->toBeEmpty();
});

test('ToolResultMessage: top-level tool_name takes precedence over nested', function () {
    // If both exist, top-level should win
    $data = [
        'role' => 'tool',
        'content' => [
            'content' => 'Result',
            'tool_call_id' => 'call_both',
            'tool_name' => 'nested_tool',
        ],
        'tool_call_id' => 'call_both',
        'tool_name' => 'top_level_tool',
    ];

    $message = ToolResultMessage::fromArray($data);

    expect($message->getToolName())->toBe('top_level_tool');
});

test('ToolResultMessage: handles empty tool_name gracefully', function () {
    $data = [
        'role' => 'tool',
        'content' => 'Simple content',
        'tool_call_id' => 'call_empty',
    ];

    $message = ToolResultMessage::fromArray($data);

    expect($message->getToolName())->toBe('');
});
