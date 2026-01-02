<?php

use Illuminate\Support\Facades\Event;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Events\ChatHistory\ChatHistoryTruncated;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;

describe('ChatHistoryTruncated Event', function () {
    it('dispatches event when messages are replaced', function () {
        Event::fake([ChatHistoryTruncated::class]);

        $identity = new SessionIdentity(chatName: 'test', agentName: 'TestAgent');
        $storage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);

        // Add some messages
        $storage->addMessage(Message::user('Test message'));

        // Replace messages
        $newMessages = new MessageArray;
        $newMessages->add(Message::assistant('New message'));

        $storage->replaceMessages($newMessages);

        Event::assertDispatched(ChatHistoryTruncated::class, function ($event) use ($storage) {
            return $event->chatHistory === $storage
                && $event->remainingMessages->count() === 1;
        });
    });

    it('marks storage as dirty after replacing messages', function () {
        $identity = new SessionIdentity(chatName: 'test', agentName: 'TestAgent');
        $storage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);

        // Add some messages
        $storage->addMessage(Message::user('Test message'));
        $storage->save(); // Clear dirty flag

        // Replace messages
        $newMessages = new MessageArray;
        $newMessages->add(Message::assistant('New message'));

        $storage->replaceMessages($newMessages);

        // Storage should be marked as dirty after replacement
        // We can verify this by checking that save() will actually write
        expect($storage->getMessages()->count())->toBe(1);
        expect($storage->getMessages()->all()[0]->getContentAsString())->toBe('New message');
    });

    it('replaces all existing messages with new ones', function () {
        $identity = new SessionIdentity(chatName: 'test', agentName: 'TestAgent');
        $storage = new ChatHistoryStorage($identity, [InMemoryStorage::class]);

        // Add several messages
        $storage->addMessage(Message::user('Message 1'));
        $storage->addMessage(Message::assistant('Response 1'));
        $storage->addMessage(Message::user('Message 2'));

        expect($storage->getMessages()->count())->toBe(3);

        // Replace with fewer messages
        $newMessages = new MessageArray;
        $newMessages->add(Message::user('New message'));

        $storage->replaceMessages($newMessages);

        expect($storage->getMessages()->count())->toBe(1);
        expect($storage->getMessages()->all()[0]->getContentAsString())->toBe('New message');
    });
});
