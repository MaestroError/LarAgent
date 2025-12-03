<?php

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Messages\UserMessage;
use LarAgent\Messages\AssistantMessage;
use LarAgent\Messages\SystemMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

// Helper to create identity
function createChatIdentity(string $agent, ?string $chat = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat);
}

test('ChatHistoryStorage: Can be constructed', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([InMemoryStorage::class], $identity);

    expect($storage)->toBeInstanceOf(ChatHistoryStorage::class);
    expect($storage->getIdentifier())->toBe('chatHistory_agent_chat');
});

test('ChatHistoryStorage: getMessages returns MessageArray', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $messages = $storage->getMessages();

    expect($messages)->toBeInstanceOf(MessageArray::class);
    expect($messages->isEmpty())->toBeTrue();
});

test('ChatHistoryStorage: addMessage adds message', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $storage->addMessage(new UserMessage('Hello'));

    expect($storage->count())->toBe(1);
    expect($storage->getMessages()[0])->toBeInstanceOf(UserMessage::class);
    expect($storage->isDirty())->toBeTrue();
});

test('ChatHistoryStorage: addMessage with different message types', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $storage->addMessage(new SystemMessage('You are helpful'));
    $storage->addMessage(new UserMessage('Hello'));
    $storage->addMessage(new AssistantMessage('Hi there!'));

    expect($storage->count())->toBe(3);
    expect($storage->getMessages()[0])->toBeInstanceOf(SystemMessage::class);
    expect($storage->getMessages()[1])->toBeInstanceOf(UserMessage::class);
    expect($storage->getMessages()[2])->toBeInstanceOf(AssistantMessage::class);
});

test('ChatHistoryStorage: getLastMessage returns last message', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $storage->addMessage(new UserMessage('First'));
    $storage->addMessage(new AssistantMessage('Second'));

    $last = $storage->getLastMessage();

    expect($last)->toBeInstanceOf(AssistantMessage::class);
    expect((string) $last->getContent())->toBe('Second');
});

test('ChatHistoryStorage: getLastMessage returns null when empty', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    expect($storage->getLastMessage())->toBeNull();
});

test('ChatHistoryStorage: clear removes all messages', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $storage->addMessage(new UserMessage('Hello'));
    $storage->addMessage(new AssistantMessage('Hi'));

    expect($storage->count())->toBe(2);

    $storage->clear();

    expect($storage->count())->toBe(0);
    expect($storage->getMessages()->isEmpty())->toBeTrue();
});

test('ChatHistoryStorage: toArray returns messages as array', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $storage->addMessage(new UserMessage('Hello'));
    $storage->addMessage(new AssistantMessage('Hi'));

    $array = $storage->toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(2);
    expect($array[0]['role'])->toBe('user');
    expect($array[1]['role'])->toBe('assistant');
});

test('ChatHistoryStorage: toArrayWithMeta includes metadata', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    $message = new UserMessage('Hello');
    $message->setMetadata(['custom' => 'data']);
    $storage->addMessage($message);

    $arrayWithMeta = $storage->toArrayWithMeta();

    expect($arrayWithMeta[0]['metadata'])->toBe(['custom' => 'data']);
});

test('ChatHistoryStorage: storeMeta is false by default', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    expect($storage->shouldStoreMeta())->toBeFalse();
});

test('ChatHistoryStorage: storeMeta can be enabled', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity, true);

    expect($storage->shouldStoreMeta())->toBeTrue();
});

test('ChatHistoryStorage: setStoreMeta changes setting', function () {
    $identity = createChatIdentity('agent', 'chat');
    $storage = new ChatHistoryStorage([new InMemoryStorage()], $identity);

    expect($storage->shouldStoreMeta())->toBeFalse();

    $storage->setStoreMeta(true);

    expect($storage->shouldStoreMeta())->toBeTrue();
});

test('ChatHistoryStorage: Persists and loads messages correctly', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    // Create storage and add messages
    $storage1 = new ChatHistoryStorage([$driver], $identity);
    $storage1->addMessage(new SystemMessage('You are helpful'));
    $storage1->addMessage(new UserMessage('Hello'));
    $storage1->addMessage(new AssistantMessage('Hi there!'));
    $storage1->save();

    // Create new storage instance and load
    $storage2 = new ChatHistoryStorage([$driver], $identity);
    $storage2->readFromMemory();

    expect($storage2->count())->toBe(3);
    expect($storage2->getMessages()[0])->toBeInstanceOf(SystemMessage::class);
    expect($storage2->getMessages()[1])->toBeInstanceOf(UserMessage::class);
    expect($storage2->getMessages()[2])->toBeInstanceOf(AssistantMessage::class);
});

test('ChatHistoryStorage: Always stores usage data', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    // Create storage and add message with usage
    $storage1 = new ChatHistoryStorage([$driver], $identity);
    $assistantMessage = new AssistantMessage('Response');
    $assistantMessage->setUsage(new Usage(100, 50, 150));
    $storage1->addMessage($assistantMessage);
    $storage1->save();

    // Load in new storage
    $storage2 = new ChatHistoryStorage([$driver], $identity);
    $storage2->readFromMemory();

    $loadedMessage = $storage2->getMessages()[0];
    expect($loadedMessage->getUsage())->not->toBeNull();
    expect($loadedMessage->getUsage()->promptTokens)->toBe(100);
    expect($loadedMessage->getUsage()->completionTokens)->toBe(50);
    expect($loadedMessage->getUsage()->totalTokens)->toBe(150);
});

test('ChatHistoryStorage: Does not store metadata when storeMeta is false', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    // Create storage with storeMeta = false (default)
    $storage1 = new ChatHistoryStorage([$driver], $identity, false);
    $message = new UserMessage('Hello');
    $message->setMetadata(['secret' => 'data']);
    $storage1->addMessage($message);
    $storage1->save();

    // Read raw data from driver
    $scopedIdentity = $identity->withScope('chatHistory');
    $rawData = $driver->readFromMemory($scopedIdentity);

    // Metadata should not be in raw storage
    expect($rawData[0])->not->toHaveKey('metadata');
});

test('ChatHistoryStorage: Stores metadata when storeMeta is true', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    // Create storage with storeMeta = true
    $storage1 = new ChatHistoryStorage([$driver], $identity, true);
    $message = new UserMessage('Hello');
    $message->setMetadata(['secret' => 'data']);
    $storage1->addMessage($message);
    $storage1->save();

    // Read raw data from driver
    $scopedIdentity = $identity->withScope('chatHistory');
    $rawData = $driver->readFromMemory($scopedIdentity);

    // Metadata should be in raw storage
    expect($rawData[0]['metadata'])->toBe(['secret' => 'data']);
});

test('ChatHistoryStorage: readFromMemory forces load', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    // Pre-populate driver
    $scopedIdentity = $identity->withScope('chatHistory');
    $driver->writeToMemory($scopedIdentity, [
        ['role' => 'user', 'content' => 'Pre-existing']
    ]);

    $storage = new ChatHistoryStorage([$driver], $identity);

    // Before read
    expect($storage->isLoaded())->toBeFalse();

    $storage->readFromMemory();

    expect($storage->isLoaded())->toBeTrue();
    expect($storage->count())->toBe(1);
    expect((string) $storage->getMessages()[0]->getContent())->toBe('Pre-existing');
});

test('ChatHistoryStorage: writeToMemory forces write', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    $storage = new ChatHistoryStorage([$driver], $identity);
    $storage->addMessage(new UserMessage('Test'));

    // Force write without checking dirty
    $storage->writeToMemory();

    // Verify written
    $scopedIdentity = $identity->withScope('chatHistory');
    $rawData = $driver->readFromMemory($scopedIdentity);
    expect($rawData)->toHaveCount(1);
    expect($rawData[0]['role'])->toBe('user');
});

test('ChatHistoryStorage: save only saves when dirty', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    $storage = new ChatHistoryStorage([$driver], $identity);

    // Not dirty, should not write
    expect($storage->isDirty())->toBeFalse();
    $storage->save();

    $scopedIdentity = $identity->withScope('chatHistory');
    expect($driver->readFromMemory($scopedIdentity))->toBeNull();

    // Add message makes it dirty
    $storage->addMessage(new UserMessage('Hello'));
    expect($storage->isDirty())->toBeTrue();

    $storage->save();
    expect($storage->isDirty())->toBeFalse();
    expect($driver->readFromMemory($scopedIdentity))->not->toBeNull();
});

test('ChatHistoryStorage: Has isolated storage prefix', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    $storage = new ChatHistoryStorage([$driver], $identity);

    // The internal scoped identity should have 'chatHistory' prefix
    expect($storage->getIdentity()->getScope())->toBe('chatHistory');
    expect($storage->getIdentifier())->toContain('chatHistory');
});

test('ChatHistoryStorage: Handles ToolCallMessage', function () {
    $driver = new InMemoryStorage();
    $identity = createChatIdentity('agent', 'chat');

    $storage1 = new ChatHistoryStorage([$driver], $identity);

    $toolCall = new ToolCall('call_123', 'get_weather', '{"location": "London"}');
    $toolCallMessage = new ToolCallMessage([$toolCall]);
    $storage1->addMessage($toolCallMessage);
    $storage1->save();

    // Load in new instance
    $storage2 = new ChatHistoryStorage([$driver], $identity);
    $storage2->readFromMemory();

    expect($storage2->getMessages()[0])->toBeInstanceOf(ToolCallMessage::class);
    expect($storage2->getMessages()[0]->getToolCalls())->toHaveCount(1);
});
