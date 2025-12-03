<?php

use LarAgent\History\InMemoryChatHistory;
use LarAgent\Messages\UserMessage;
use LarAgent\Context\SessionIdentity;

it('can add and retrieve messages', function () {
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-history'));
    $message = new UserMessage('What\'s the weather like in Boston? I prefer celsius');

    $chatHistory->addMessage($message);

    expect($chatHistory->getMessages())
        ->toHaveCount(1)
        ->and($chatHistory->getLastMessage()->getContent()->toArray())
        ->toBe([
            [
                'type' => 'text',
                'text' => 'What\'s the weather like in Boston? I prefer celsius',
            ],
        ]);
});

it('can clear messages', function () {
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-history'));
    $chatHistory->addMessage(new UserMessage('Message 1'));
    $chatHistory->addMessage(new UserMessage('Message 2'));

    $chatHistory->clear();

    expect($chatHistory->getMessages())->toBeEmpty();
});

it('supports array access for messages', function () {
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-history'));
    $message = new UserMessage('This is an array-accessible message');

    $chatHistory->addMessage($message);

    // Get history's MessageArray
    $chatHistoryArray = $chatHistory->getMessages();
    expect($chatHistoryArray[0])
        ->toBeInstanceOf(UserMessage::class)
        ->and($chatHistoryArray[0]->getContent()->toArray())
        ->toBe([
            [
                'type' => 'text',
                'text' => 'This is an array-accessible message',
            ],
        ]);

    unset($chatHistoryArray[0]);

    expect(isset($chatHistoryArray[0]))->toBeFalse();
});

it('can write and read messages to and from memory', function () {
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('test-memory-history'));
    $message = new UserMessage('Remember this message in memory');

    $chatHistory->addMessage($message);
    $chatHistory->writeToMemory();

    // Clear and reload from memory
    $chatHistory->clear();
    $chatHistory->readFromMemory();

    expect($chatHistory->getMessages())
        ->toHaveCount(1)
        ->and($chatHistory->getMessages()[0]->getContent()->toArray())
        ->toBe([
            [
                'type' => 'text',
                'text' => 'Remember this message in memory',
            ],
        ]);
});

it('handles empty memory gracefully', function () {
    $chatHistory = new InMemoryChatHistory([], new SessionIdentity('empty-memory-history'));

    // Ensure no errors occur when reading from empty memory
    $chatHistory->readFromMemory();

    expect($chatHistory->getMessages())->toBeEmpty();
});

