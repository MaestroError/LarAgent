<?php

use Illuminate\Support\Facades\Storage;
use LarAgent\Core\Enums\Role;
use LarAgent\History\CacheChatHistory;
use LarAgent\History\FileChatHistory;
use LarAgent\History\JsonChatHistory;
use LarAgent\History\SessionChatHistory;
use LarAgent\Message;

dataset('messages', [
    Message::create(Role::SYSTEM->value, 'You are helpful assistant', ['test' => 'meta']),
]);

it('can read and write session chat history', function ($message) {
    $history = new SessionChatHistory('test_session');
    $history->addMessage($message);
    $history->writeToMemory();

    $newHistory = new SessionChatHistory('test_session');
    expect($newHistory->getMessages())->toBe([$message]);
})->with('messages');

it('can read and write json chat history', function ($message) {
    $history = new JsonChatHistory('test_json', ['folder' => __DIR__.'/json_storage']);
    $history->clear();

    $history->addMessage($message);
    $history->writeToMemory();

    $newHistory = new JsonChatHistory('test_json', ['folder' => __DIR__.'/json_storage']);

    expect($newHistory->toArray())->toBe([$message->toArray()]);
    expect($newHistory->getLastMessage()->toArray())->toBe($message->toArray());

})->with('messages');

it('can read and write file chat history', function ($message) {
    Storage::fake('local');
    $history = new FileChatHistory('test_file', ['disk' => 'local', 'folder' => 'chat_histories']);
    $history->addMessage($message);
    $history->writeToMemory();

    $newHistory = new FileChatHistory('test_file', ['disk' => 'local', 'folder' => 'chat_histories']);

    expect($newHistory->toArray())->toBe([$message->toArray()]);
    expect($newHistory->getLastMessage()->toArray())->toBe($message->toArray());
})->with('messages');

it('reads and writes chat history using the default cache store', function ($message) {
    $identifier = 'test_cache';

    $history = new CacheChatHistory($identifier);
    $history->addMessage($message);
    $history->writeToMemory();

    $newHistory = new CacheChatHistory($identifier);

    expect($newHistory->toArray())->toBe([$message->toArray()]);
    expect($newHistory->getLastMessage()->toArray())->toBe($message->toArray());
})->with('messages');

it('can save and load keys in session chat history', function () {
    $firstHistory = new SessionChatHistory('test_session_1');
    $secondHistory = new SessionChatHistory('test_session_2');

    // Save keys
    $firstHistory->saveKeyToMemory();
    $secondHistory->saveKeyToMemory();

    // Load and verify keys
    $keys = $firstHistory->loadKeysFromMemory();
    expect($keys)->toBeArray()
        ->toContain('test_session_1')
        ->toContain('test_session_2')
        ->toHaveCount(2);

    // Verify no duplicates when saving same key again
    $firstHistory->saveKeyToMemory();
    expect($firstHistory->loadKeysFromMemory())->toHaveCount(2);
});

it('can save and load keys in json chat history', function () {
    $firstHistory = new JsonChatHistory('test_json', ['folder' => __DIR__.'/json_storage']);
    $secondHistory = new JsonChatHistory('test_json_2', ['folder' => __DIR__.'/json_storage']);

    // Save keys
    $firstHistory->saveKeyToMemory();
    $secondHistory->saveKeyToMemory();

    // Load and verify keys
    $keys = $firstHistory->loadKeysFromMemory();
    expect($keys)->toBeArray()
        ->toContain('test_json')
        ->toContain('test_json_2')
        ->toHaveCount(2);

    // Verify no duplicates when saving same key again
    $firstHistory->saveKeyToMemory();
    expect($firstHistory->loadKeysFromMemory())->toHaveCount(2);

    // Cleanup
    $firstHistory->clear();
    $secondHistory->clear();
});

it('can save and load keys in file chat history', function () {
    Storage::fake('local');
    $firstHistory = new FileChatHistory('test_file_1', ['disk' => 'local', 'folder' => 'chat_histories']);
    $secondHistory = new FileChatHistory('test_file_2', ['disk' => 'local', 'folder' => 'chat_histories']);

    // Save keys
    $firstHistory->saveKeyToMemory();
    $secondHistory->saveKeyToMemory();

    // Load and verify keys
    $keys = $firstHistory->loadKeysFromMemory();
    expect($keys)->toBeArray()
        ->toContain('test_file_1')
        ->toContain('test_file_2')
        ->toHaveCount(2);

    // Verify no duplicates when saving same key again
    $firstHistory->saveKeyToMemory();
    expect($firstHistory->loadKeysFromMemory())->toHaveCount(2);
});

it('can save and load keys in cache chat history', function () {
    $firstHistory = new CacheChatHistory('test_cache_1');
    $secondHistory = new CacheChatHistory('test_cache_2');

    // Save keys
    $firstHistory->saveKeyToMemory();
    $secondHistory->saveKeyToMemory();

    // Load and verify keys
    $keys = $firstHistory->loadKeysFromMemory();
    expect($keys)->toBeArray()
        ->toContain('test_cache_1')
        ->toContain('test_cache_2')
        ->toHaveCount(2);

    // Verify no duplicates when saving same key again
    $firstHistory->saveKeyToMemory();
    expect($firstHistory->loadKeysFromMemory())->toHaveCount(2);
});

it('can remove chat from session history', function () {
    $history = new SessionChatHistory('test_session_remove');
    $message = Message::create(Role::SYSTEM->value, 'Test message');

    $history->addMessage($message);
    $history->writeToMemory();
    $history->saveKeyToMemory();

    // Verify chat exists
    expect(Session::has('test_session_remove'))->toBeTrue()
        ->and($history->loadKeysFromMemory())->toContain('test_session_remove');

    // Remove chat
    $history->removeChatFromMemory('test_session_remove');

    // Verify chat is removed
    expect(Session::has('test_session_remove'))->toBeFalse()
        ->and($history->loadKeysFromMemory())->not->toContain('test_session_remove');
});

it('can remove chat from json history', function () {
    $history = new JsonChatHistory('test_json_remove', ['folder' => __DIR__.'/json_storage']);
    $message = Message::create(Role::SYSTEM->value, 'Test message');

    $history->addMessage($message);
    $history->writeToMemory();
    $history->saveKeyToMemory();

    $filePath = __DIR__.'/json_storage/test_json_remove.json';

    // Verify chat exists
    expect(file_exists($filePath))->toBeTrue()
        ->and($history->loadKeysFromMemory())->toContain('test_json_remove');

    // Remove chat
    $history->removeChatFromMemory('test_json_remove');

    // Verify chat is removed
    expect(file_exists($filePath))->toBeFalse()
        ->and($history->loadKeysFromMemory())->not->toContain('test_json_remove');
});

it('can remove chat from file history', function () {
    Storage::fake('local');
    $history = new FileChatHistory('test_file_remove', ['disk' => 'local', 'folder' => 'chat_histories']);
    $message = Message::create(Role::SYSTEM->value, 'Test message');

    $history->addMessage($message);
    $history->writeToMemory();
    $history->saveKeyToMemory();

    // Verify chat exists
    expect(Storage::disk('local')->exists('chat_histories/test_file_remove.json'))->toBeTrue()
        ->and($history->loadKeysFromMemory())->toContain('test_file_remove');

    // Remove chat
    $history->removeChatFromMemory('test_file_remove');

    // Verify chat is removed
    expect(Storage::disk('local')->exists('chat_histories/test_file_remove.json'))->toBeFalse()
        ->and($history->loadKeysFromMemory())->not->toContain('test_file_remove');
});

it('can remove chat from cache history', function () {
    $history = new CacheChatHistory('test_cache_remove');
    $message = Message::create(Role::SYSTEM->value, 'Test message');

    $history->addMessage($message);
    $history->writeToMemory();
    $history->saveKeyToMemory();

    // Verify chat exists
    expect(Cache::has('test_cache_remove'))->toBeTrue()
        ->and($history->loadKeysFromMemory())->toContain('test_cache_remove');

    // Remove chat
    $history->removeChatFromMemory('test_cache_remove');

    // Verify chat is removed
    expect(Cache::has('test_cache_remove'))->toBeFalse()
        ->and($history->loadKeysFromMemory())->not->toContain('test_cache_remove');
});

it('can remove chat from custom cache store', function () {
    $history = new CacheChatHistory('test_store_remove', ['store' => 'array']);
    $message = Message::create(Role::SYSTEM->value, 'Test message');

    $history->addMessage($message);
    $history->writeToMemory();
    $history->saveKeyToMemory();

    // Verify chat exists in custom store
    expect(Cache::store('array')->has('test_store_remove'))->toBeTrue()
        ->and($history->loadKeysFromMemory())->toContain('test_store_remove');

    // Remove chat
    $history->removeChatFromMemory('test_store_remove');

    // Verify chat is removed from custom store
    expect(Cache::store('array')->has('test_store_remove'))->toBeFalse()
        ->and($history->loadKeysFromMemory())->not->toContain('test_store_remove');
});
