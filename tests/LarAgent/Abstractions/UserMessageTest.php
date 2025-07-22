<?php

use LarAgent\Core\Enums\Role;
use LarAgent\Message;
use LarAgent\Messages\UserMessage;

it('creates a user message with content and metadata', function () {
    $message = new UserMessage('What is the weather in Boston?', ['key' => 'value']);

    expect($message->getRole())->toBe(Role::USER->value)
        ->and($message->getContent())->toBe('What is the weather in Boston?')
        ->and($message->getMetadata())->toHaveKey('key', 'value');
});

it('converts the message to an array', function () {
    $message = new UserMessage('What is the weather in Boston?');

    expect($message->toArray())->toMatchArray([
        'role' => Role::USER->value,
        'content' => 'What is the weather in Boston?',
    ]);
});

it('supports array access', function () {
    $message = new UserMessage('What is the weather in Boston?');

    expect(isset($message['role']))->toBeTrue()
        ->and($message['role'])->toBe(Role::USER->value)
        ->and($message['content'])->toBe('What is the weather in Boston?');
});

it('throws an exception when trying to modify via array access', function () {
    $message = new UserMessage('What is the weather in Boston?');

    $message['role'] = 'agent';
})->throws(BadMethodCallException::class, 'Message is immutable.');

it('throws an exception when trying to unset via array access', function () {
    $message = new UserMessage('What is the weather in Boston?');

    unset($message['role']);
})->throws(BadMethodCallException::class, 'Message is immutable.');

it('can be cast to a string', function () {
    $message = new UserMessage('What is the weather in Boston?');

    expect((string) $message)->toBe('What is the weather in Boston?');
});

it('uses the first text element if content is an array', function () {
    $message = Message::create(
        Role::USER->value,
        [
            ['text' => 'What is the weather in Boston?'],
            [
                'image_url' => [
                    'url' => 'https://example.com/image.jpg',
                ],
            ],
        ]
    );

    expect((string) $message)->toBe('What is the weather in Boston?');
});

it('can set metadata', function () {
    $message = new UserMessage('What is the weather in Boston?');
    $message->setMetadata(['new_key' => 'new_value']);

    expect($message->getMetadata())->toMatchArray(['new_key' => 'new_value']);
});

it('handles empty content gracefully', function () {
    $message = new UserMessage('');

    expect($message->getContent())->toBe('');
});
