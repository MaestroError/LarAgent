<?php

use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Usage\DataModels\UsageArray;
use LarAgent\Usage\Storages\UsageStorage;

// Helper to create identity
function createUsageIdentity(string $agent, ?string $chat = null, ?string $userId = null): SessionIdentity
{
    return new SessionIdentity($agent, $chat, $userId);
}

test('UsageStorage: Can be constructed', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [InMemoryStorage::class]);

    expect($storage)->toBeInstanceOf(UsageStorage::class);
    expect($storage->getIdentifier())->toBe('usage_agent_chat');
});

test('UsageStorage: getUsages returns UsageArray', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $usages = $storage->getUsages();

    expect($usages)->toBeInstanceOf(UsageArray::class);
    expect($usages->isEmpty())->toBeTrue();
});

test('UsageStorage: addUsage adds usage', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $usage = new Usage(100, 50, 150, 'user123', 'group1', 'chat', null, 'gpt-4', 'openai', 'TestAgent');
    $storage->addUsage($usage);

    expect($storage->count())->toBe(1);
    expect($storage->getUsages()[0])->toBeInstanceOf(Usage::class);
    expect($storage->isDirty())->toBeTrue();
});

test('UsageStorage: getLastUsage returns last usage', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'model1', 'provider1', 'agent1'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, null, 'model2', 'provider2', 'agent2'));

    $last = $storage->getLastUsage();

    expect($last)->toBeInstanceOf(Usage::class);
    expect($last->promptTokens)->toBe(200);
    expect($last->completionTokens)->toBe(100);
});

test('UsageStorage: getLastUsage returns null when empty', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    expect($storage->getLastUsage())->toBeNull();
});

test('UsageStorage: clear removes all usages', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50));
    $storage->addUsage(new Usage(200, 100));

    expect($storage->count())->toBe(2);

    $storage->clear();

    expect($storage->count())->toBe(0);
    expect($storage->getUsages()->isEmpty())->toBeTrue();
});

test('UsageStorage: toArray returns usages as array', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', 'group1', 'chat', null, 'model1', 'provider1', 'agent1'));
    $array = $storage->toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(1);
    expect($array[0]['prompt_tokens'])->toBe(100);
    expect($array[0]['completion_tokens'])->toBe(50);
    expect($array[0]['user_id'])->toBe('user1');
    expect($array[0]['model'])->toBe('model1');
});

test('UsageStorage: saves and loads from storage', function () {
    $identity = createUsageIdentity('agent', 'chat_persist');
    $driver = new InMemoryStorage;
    $storage = new UsageStorage($identity, [$driver]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'model1', 'provider1', 'agent1'));
    $storage->save();

    // Create new storage instance to test persistence
    $storage2 = new UsageStorage($identity, [$driver]);
    $usages = $storage2->getUsages();

    expect($usages)->toHaveCount(1);
    expect($usages[0]->promptTokens)->toBe(100);
});

test('UsageStorage: filterByUserId filters correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'model1', 'provider1', 'agent1'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, null, 'model2', 'provider2', 'agent2'));
    $storage->addUsage(new Usage(150, 75, 225, 'user1', null, null, null, 'model3', 'provider3', 'agent3'));

    $filtered = $storage->filterByUserId('user1');

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]->userId)->toBe('user1');
    expect($filtered[1]->userId)->toBe('user1');
});

test('UsageStorage: filterByModel filters correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'gpt-4', 'openai', 'agent1'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, null, 'gpt-3.5', 'openai', 'agent2'));
    $storage->addUsage(new Usage(150, 75, 225, 'user3', null, null, null, 'gpt-4', 'openai', 'agent3'));

    $filtered = $storage->filterByModel('gpt-4');

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]->model)->toBe('gpt-4');
    expect($filtered[1]->model)->toBe('gpt-4');
});

test('UsageStorage: filterByProvider filters correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'model1', 'openai', 'agent1'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, null, 'model2', 'anthropic', 'agent2'));
    $storage->addUsage(new Usage(150, 75, 225, 'user3', null, null, null, 'model3', 'openai', 'agent3'));

    $filtered = $storage->filterByProvider('openai');

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]->provider)->toBe('openai');
    expect($filtered[1]->provider)->toBe('openai');
});

test('UsageStorage: filterByAgent filters correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'model1', 'provider1', 'TestAgent'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, null, 'model2', 'provider2', 'OtherAgent'));
    $storage->addUsage(new Usage(150, 75, 225, 'user3', null, null, null, 'model3', 'provider3', 'TestAgent'));

    $filtered = $storage->filterByAgent('TestAgent');

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]->agent)->toBe('TestAgent');
    expect($filtered[1]->agent)->toBe('TestAgent');
});

test('UsageStorage: filterByDateRange filters correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $date1 = '2024-01-01T10:00:00+00:00';
    $date2 = '2024-01-15T10:00:00+00:00';
    $date3 = '2024-02-01T10:00:00+00:00';

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, $date1, 'model1', 'provider1', 'agent1'));
    $storage->addUsage(new Usage(200, 100, 300, 'user2', null, null, $date2, 'model2', 'provider2', 'agent2'));
    $storage->addUsage(new Usage(150, 75, 225, 'user3', null, null, $date3, 'model3', 'provider3', 'agent3'));

    $filtered = $storage->filterByDateRange('2024-01-10', '2024-01-20');

    expect($filtered)->toHaveCount(1);
    expect($filtered[0]->createdAt)->toBe($date2);
});

test('UsageStorage: getTotalUsage aggregates correctly', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150));
    $storage->addUsage(new Usage(200, 100, 300));
    $storage->addUsage(new Usage(150, 75, 225));

    $total = $storage->getTotalUsage();

    expect($total['prompt_tokens'])->toBe(450);
    expect($total['completion_tokens'])->toBe(225);
    expect($total['total_tokens'])->toBe(675);
});

test('UsageStorage: filter chaining works', function () {
    $identity = createUsageIdentity('agent', 'chat');
    $storage = new UsageStorage($identity, [new InMemoryStorage]);

    $storage->addUsage(new Usage(100, 50, 150, 'user1', null, null, null, 'gpt-4', 'openai', 'TestAgent'));
    $storage->addUsage(new Usage(200, 100, 300, 'user1', null, null, null, 'gpt-4', 'anthropic', 'TestAgent'));
    $storage->addUsage(new Usage(150, 75, 225, 'user2', null, null, null, 'gpt-4', 'openai', 'TestAgent'));
    $storage->addUsage(new Usage(120, 60, 180, 'user1', null, null, null, 'gpt-3.5', 'openai', 'TestAgent'));

    // Filter by user and model
    $filtered = $storage->filterByUserId('user1')->filter(function ($usage) {
        return $usage->model === 'gpt-4';
    });

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]->userId)->toBe('user1');
    expect($filtered[0]->model)->toBe('gpt-4');
});
