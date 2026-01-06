<?php

use LarAgent\ToolCall;

describe('ToolCall', function () {
    // ========== Basic Construction Tests ==========

    it('creates a tool call with basic properties', function () {
        $toolCall = new ToolCall('call_123', 'get_weather', '{"city": "Tokyo"}');

        expect($toolCall->getId())->toBe('call_123')
            ->and($toolCall->getToolName())->toBe('get_weather')
            ->and($toolCall->getArguments())->toBe('{"city": "Tokyo"}');
    });

    it('throws exception for invalid JSON arguments', function () {
        new ToolCall('call_123', 'test', 'invalid-json');
    })->throws(InvalidArgumentException::class);

    // ========== Thought Signature Tests ==========

    it('creates tool call without thought signature by default', function () {
        $toolCall = new ToolCall('call_123', 'test', '{}');

        expect($toolCall->hasThoughtSignature())->toBeFalse()
            ->and($toolCall->getThoughtSignature())->toBeNull();
    });

    it('creates tool call with thought signature via constructor', function () {
        $toolCall = new ToolCall('call_123', 'check_flight', '{"flight": "AA100"}', '<Signature_A>');

        expect($toolCall->hasThoughtSignature())->toBeTrue()
            ->and($toolCall->getThoughtSignature())->toBe('<Signature_A>');
    });

    it('can set thought signature after construction', function () {
        $toolCall = new ToolCall('call_123', 'test', '{}');
        $toolCall->setThoughtSignature('<New_Signature>');

        expect($toolCall->hasThoughtSignature())->toBeTrue()
            ->and($toolCall->getThoughtSignature())->toBe('<New_Signature>');
    });

    it('can clear thought signature by setting to null', function () {
        $toolCall = new ToolCall('call_123', 'test', '{}', '<Signature>');
        $toolCall->setThoughtSignature(null);

        expect($toolCall->hasThoughtSignature())->toBeFalse()
            ->and($toolCall->getThoughtSignature())->toBeNull();
    });

    it('setThoughtSignature returns self for chaining', function () {
        $toolCall = new ToolCall('call_123', 'test', '{}');
        $result = $toolCall->setThoughtSignature('<Signature>');

        expect($result)->toBe($toolCall);
    });

    // ========== Serialization Tests ==========

    it('includes thought signature in toArray when present', function () {
        $toolCall = new ToolCall('call_123', 'check_flight', '{"flight": "AA100"}', '<Signature_A>');
        $array = $toolCall->toArray();

        expect($array)->toHaveKey('thought_signature')
            ->and($array['thought_signature'])->toBe('<Signature_A>');
    });

    it('does not include thought signature in toArray when null', function () {
        $toolCall = new ToolCall('call_123', 'test', '{}');
        $array = $toolCall->toArray();

        expect($array)->not->toHaveKey('thought_signature');
    });

    it('preserves thought signature through fromArray', function () {
        $originalArray = [
            'id' => 'call_456',
            'type' => 'function',
            'function' => [
                'name' => 'book_taxi',
                'arguments' => '{"time": "10 AM"}',
            ],
            'thought_signature' => '<Signature_B>',
        ];

        $toolCall = ToolCall::fromArray($originalArray);

        expect($toolCall->getId())->toBe('call_456')
            ->and($toolCall->getToolName())->toBe('book_taxi')
            ->and($toolCall->getArguments())->toBe('{"time": "10 AM"}')
            ->and($toolCall->hasThoughtSignature())->toBeTrue()
            ->and($toolCall->getThoughtSignature())->toBe('<Signature_B>');
    });

    it('creates tool call without signature from array when not present', function () {
        $array = [
            'id' => 'call_789',
            'type' => 'function',
            'function' => [
                'name' => 'get_time',
                'arguments' => '{}',
            ],
        ];

        $toolCall = ToolCall::fromArray($array);

        expect($toolCall->hasThoughtSignature())->toBeFalse();
    });

    it('round-trips thought signature through toArray and fromArray', function () {
        $original = new ToolCall('call_round', 'test_tool', '{"key": "value"}', '<RoundTrip_Signature>');
        $array = $original->toArray();
        $restored = ToolCall::fromArray($array);

        expect($restored->getId())->toBe($original->getId())
            ->and($restored->getToolName())->toBe($original->getToolName())
            ->and($restored->getArguments())->toBe($original->getArguments())
            ->and($restored->getThoughtSignature())->toBe($original->getThoughtSignature());
    });
});
