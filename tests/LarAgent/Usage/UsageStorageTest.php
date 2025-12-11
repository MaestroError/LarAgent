<?php

use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Context\SessionIdentity;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Usage\DataModels\UsageArray;
use LarAgent\Usage\DataModels\UsageRecord;
use LarAgent\Usage\UsageStorage;

// Test UsageRecord DataModel
describe('UsageRecord DataModel', function () {
    it('can create a usage record with all fields', function () {
        $record = new UsageRecord(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            agentName: 'TestAgent',
            userId: 'user123',
            group: 'group1',
            chatName: 'chat1',
            modelName: 'gpt-4',
            providerName: 'openai'
        );

        expect($record->promptTokens)->toBe(100);
        expect($record->completionTokens)->toBe(50);
        expect($record->totalTokens)->toBe(150);
        expect($record->agentName)->toBe('TestAgent');
        expect($record->userId)->toBe('user123');
        expect($record->group)->toBe('group1');
        expect($record->chatName)->toBe('chat1');
        expect($record->modelName)->toBe('gpt-4');
        expect($record->providerName)->toBe('openai');
        expect($record->recordId)->toStartWith('usage_');
        expect($record->recordedAt)->not->toBeEmpty();
    });

    it('auto-generates id and timestamp', function () {
        $record = new UsageRecord(promptTokens: 100, completionTokens: 50);

        expect($record->recordId)->toStartWith('usage_');
        expect($record->recordedAt)->not->toBeEmpty();
        expect($record->getRecordedAtDateTime())->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('can be created from a base Usage object', function () {
        $usage = new Usage(100, 50, 150);
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123', 'group1');

        $record = UsageRecord::fromUsage($usage, $identity, 'gpt-4', 'openai');

        expect($record->promptTokens)->toBe(100);
        expect($record->completionTokens)->toBe(50);
        expect($record->totalTokens)->toBe(150);
        expect($record->agentName)->toBe('TestAgent');
        expect($record->userId)->toBe('user123');
        expect($record->group)->toBe('group1');
        expect($record->chatName)->toBe('chat1');
        expect($record->modelName)->toBe('gpt-4');
        expect($record->providerName)->toBe('openai');
    });

    it('can be serialized to array', function () {
        $record = new UsageRecord(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            agentName: 'TestAgent',
            userId: 'user123',
            modelName: 'gpt-4',
            providerName: 'openai'
        );

        $array = $record->toArray();

        expect($array)->toHaveKey('record_id');
        expect($array['prompt_tokens'])->toBe(100);
        expect($array['completion_tokens'])->toBe(50);
        expect($array['total_tokens'])->toBe(150);
        expect($array['agent_name'])->toBe('TestAgent');
        expect($array['user_id'])->toBe('user123');
        expect($array['model_name'])->toBe('gpt-4');
        expect($array['provider_name'])->toBe('openai');
    });

    it('can be created from array', function () {
        $data = [
            'record_id' => 'usage_test123',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'agent_name' => 'TestAgent',
            'user_id' => 'user123',
            'model_name' => 'gpt-4',
            'provider_name' => 'openai',
            'recorded_at' => '2024-01-15T10:30:00+00:00',
        ];

        $record = UsageRecord::fromArray($data);

        expect($record->recordId)->toBe('usage_test123');
        expect($record->promptTokens)->toBe(100);
        expect($record->completionTokens)->toBe(50);
        expect($record->totalTokens)->toBe(150);
        expect($record->agentName)->toBe('TestAgent');
        expect($record->userId)->toBe('user123');
        expect($record->modelName)->toBe('gpt-4');
        expect($record->providerName)->toBe('openai');
        expect($record->recordedAt)->toBe('2024-01-15T10:30:00+00:00');
    });

    it('converts Carbon instance to string in fromArray', function () {
        $carbonDate = \Carbon\Carbon::parse('2024-01-15T10:30:00+00:00');

        $data = [
            'record_id' => 'usage_carbon_test',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'agent_name' => 'TestAgent',
            'recorded_at' => $carbonDate, // Carbon instance instead of string
        ];

        $record = UsageRecord::fromArray($data);

        // Should be converted to ISO 8601 string
        expect($record->recordedAt)->toBeString();
        expect($record->recordedAt)->toBe('2024-01-15T10:30:00+00:00');

        // getRecordedAtDateTime should still work for comparisons
        expect($record->getRecordedAtDateTime())->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('converts DateTimeImmutable to string in fromArray', function () {
        $dateTime = new \DateTimeImmutable('2024-01-15T10:30:00+00:00');

        $data = [
            'record_id' => 'usage_datetime_test',
            'prompt_tokens' => 100,
            'recorded_at' => $dateTime,
        ];

        $record = UsageRecord::fromArray($data);

        expect($record->recordedAt)->toBeString();
        expect($record->recordedAt)->toBe('2024-01-15T10:30:00+00:00');
    });

    it('calculates total tokens from prompt and completion when not provided', function () {
        $data = [
            'record_id' => 'usage_test456',
            'prompt_tokens' => 250,
            'completion_tokens' => 100,
            // total_tokens is intentionally omitted
            'agent_name' => 'TestAgent',
            'user_id' => 'user123',
            'model_name' => 'gpt-4',
            'provider_name' => 'openai',
            'recorded_at' => '2024-01-15T10:30:00+00:00',
        ];

        $record = UsageRecord::fromArray($data);

        expect($record->promptTokens)->toBe(250);
        expect($record->completionTokens)->toBe(100);
        // Total should be calculated as sum of prompt + completion
        expect($record->totalTokens)->toBe(350);
    });

    it('uses provided total tokens even when different from sum', function () {
        $data = [
            'record_id' => 'usage_test789',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 200, // Explicitly provided, even though sum is 150
            'agent_name' => 'TestAgent',
            'recorded_at' => '2024-01-15T10:30:00+00:00',
        ];

        $record = UsageRecord::fromArray($data);

        // Should use the provided value, not calculated
        expect($record->totalTokens)->toBe(200);
    });
});

// Test UsageArray DataModel
describe('UsageArray DataModel', function () {
    it('can store and retrieve usage records', function () {
        $array = new UsageArray;
        $record1 = new UsageRecord(promptTokens: 100, completionTokens: 50);
        $record2 = new UsageRecord(promptTokens: 200, completionTokens: 100);

        $array->add($record1);
        $array->add($record2);

        expect($array->count())->toBe(2);
        expect($array->first())->toBe($record1);
        expect($array->last())->toBe($record2);
    });

    it('can filter by agent name', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, completionTokens: 50, agentName: 'Agent1'));
        $array->add(new UsageRecord(promptTokens: 200, completionTokens: 100, agentName: 'Agent2'));
        $array->add(new UsageRecord(promptTokens: 150, completionTokens: 75, agentName: 'Agent1'));

        $filtered = $array->filterByAgent('Agent1');

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(250);
    });

    it('can filter by user id', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, userId: 'user1'));
        $array->add(new UsageRecord(promptTokens: 200, userId: 'user2'));
        $array->add(new UsageRecord(promptTokens: 150, userId: 'user1'));

        $filtered = $array->filterByUser('user1');

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(250);
    });

    it('can filter by model name', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, modelName: 'gpt-4'));
        $array->add(new UsageRecord(promptTokens: 200, modelName: 'gpt-3.5'));
        $array->add(new UsageRecord(promptTokens: 150, modelName: 'gpt-4'));

        $filtered = $array->filterByModel('gpt-4');

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(250);
    });

    it('can filter by provider name', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, providerName: 'openai'));
        $array->add(new UsageRecord(promptTokens: 200, providerName: 'anthropic'));
        $array->add(new UsageRecord(promptTokens: 150, providerName: 'openai'));

        $filtered = $array->filterByProvider('openai');

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(250);
    });

    it('can filter by date range', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, recordedAt: '2024-01-10T10:00:00+00:00'));
        $array->add(new UsageRecord(promptTokens: 200, recordedAt: '2024-01-15T10:00:00+00:00'));
        $array->add(new UsageRecord(promptTokens: 150, recordedAt: '2024-01-20T10:00:00+00:00'));

        $filtered = $array->filterByDateRange('2024-01-12', '2024-01-18');

        expect($filtered->count())->toBe(1);
        expect($filtered->getTotalPromptTokens())->toBe(200);
    });

    it('can filter by date range with records created from Carbon dates', function () {
        // Simulate records coming from Eloquent with Carbon dates
        $record1 = UsageRecord::fromArray([
            'prompt_tokens' => 100,
            'recorded_at' => \Carbon\Carbon::parse('2024-01-10T10:00:00+00:00'),
        ]);
        $record2 = UsageRecord::fromArray([
            'prompt_tokens' => 200,
            'recorded_at' => \Carbon\Carbon::parse('2024-01-15T10:00:00+00:00'),
        ]);
        $record3 = UsageRecord::fromArray([
            'prompt_tokens' => 150,
            'recorded_at' => \Carbon\Carbon::parse('2024-01-20T10:00:00+00:00'),
        ]);

        $array = new UsageArray;
        $array->add($record1);
        $array->add($record2);
        $array->add($record3);

        // Filter using Carbon dates
        $filtered = $array->filterByDateRange(
            \Carbon\Carbon::parse('2024-01-12'),
            \Carbon\Carbon::parse('2024-01-18')
        );

        expect($filtered->count())->toBe(1);
        expect($filtered->getTotalPromptTokens())->toBe(200);
    });

    it('can filter by specific date', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, recordedAt: '2024-01-15T08:00:00+00:00'));
        $array->add(new UsageRecord(promptTokens: 200, recordedAt: '2024-01-15T16:00:00+00:00'));
        $array->add(new UsageRecord(promptTokens: 150, recordedAt: '2024-01-16T10:00:00+00:00'));

        $filtered = $array->filterByDate('2024-01-15');

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(300);
    });

    it('can aggregate token totals', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, completionTokens: 50, totalTokens: 150));
        $array->add(new UsageRecord(promptTokens: 200, completionTokens: 100, totalTokens: 300));
        $array->add(new UsageRecord(promptTokens: 150, completionTokens: 75, totalTokens: 225));

        expect($array->getTotalPromptTokens())->toBe(450);
        expect($array->getTotalCompletionTokens())->toBe(225);
        expect($array->getTotalTokens())->toBe(675);

        $aggregate = $array->aggregate();
        expect($aggregate['total_prompt_tokens'])->toBe(450);
        expect($aggregate['total_completion_tokens'])->toBe(225);
        expect($aggregate['total_tokens'])->toBe(675);
        expect($aggregate['record_count'])->toBe(3);
    });

    it('can group by field', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, completionTokens: 50, totalTokens: 150, agentName: 'Agent1'));
        $array->add(new UsageRecord(promptTokens: 200, completionTokens: 100, totalTokens: 300, agentName: 'Agent2'));
        $array->add(new UsageRecord(promptTokens: 150, completionTokens: 75, totalTokens: 225, agentName: 'Agent1'));

        $grouped = $array->groupBy('agent_name');

        expect($grouped)->toHaveKey('Agent1');
        expect($grouped)->toHaveKey('Agent2');
        expect($grouped['Agent1']['total_prompt_tokens'])->toBe(250);
        expect($grouped['Agent1']['record_count'])->toBe(2);
        expect($grouped['Agent2']['total_prompt_tokens'])->toBe(200);
        expect($grouped['Agent2']['record_count'])->toBe(1);
    });

    it('can combine multiple filters', function () {
        $array = new UsageArray;
        $array->add(new UsageRecord(promptTokens: 100, agentName: 'Agent1', userId: 'user1', modelName: 'gpt-4'));
        $array->add(new UsageRecord(promptTokens: 200, agentName: 'Agent1', userId: 'user2', modelName: 'gpt-4'));
        $array->add(new UsageRecord(promptTokens: 150, agentName: 'Agent2', userId: 'user1', modelName: 'gpt-4'));
        $array->add(new UsageRecord(promptTokens: 300, agentName: 'Agent1', userId: 'user1', modelName: 'gpt-3.5'));

        // Chain filters
        $filtered = $array
            ->filterByAgent('Agent1')
            ->filterByUser('user1')
            ->filterByModel('gpt-4');

        expect($filtered->count())->toBe(1);
        expect($filtered->getTotalPromptTokens())->toBe(100);
    });
});

// Test UsageStorage
describe('UsageStorage', function () {
    it('can add and retrieve usage records', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        $usage = new Usage(100, 50, 150);
        $storage->addUsage($usage);

        $records = $storage->getUsageRecords();

        expect($records->count())->toBe(1);
        expect($records->first()->promptTokens)->toBe(100);
        expect($records->first()->agentName)->toBe('TestAgent');
        expect($records->first()->modelName)->toBe('gpt-4');
        expect($records->first()->providerName)->toBe('openai');
    });

    it('can filter usage with criteria', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        // Add multiple records
        $storage->addUsage(new Usage(100, 50, 150));
        $storage->setModelName('gpt-3.5');
        $storage->addUsage(new Usage(200, 100, 300));
        $storage->setModelName('gpt-4');
        $storage->addUsage(new Usage(150, 75, 225));

        // Filter by model
        $filtered = $storage->getFilteredUsage(['model_name' => 'gpt-4']);

        expect($filtered->count())->toBe(2);
        expect($filtered->getTotalPromptTokens())->toBe(250);
    });

    it('can aggregate usage statistics', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        $storage->addUsage(new Usage(100, 50, 150));
        $storage->addUsage(new Usage(200, 100, 300));

        $aggregate = $storage->aggregate();

        expect($aggregate['total_prompt_tokens'])->toBe(300);
        expect($aggregate['total_completion_tokens'])->toBe(150);
        expect($aggregate['total_tokens'])->toBe(450);
        expect($aggregate['record_count'])->toBe(2);
    });

    it('can group usage by field', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        $storage->addUsage(new Usage(100, 50, 150));
        $storage->setProviderName('anthropic');
        $storage->addUsage(new Usage(200, 100, 300));
        $storage->setProviderName('openai');
        $storage->addUsage(new Usage(150, 75, 225));

        $grouped = $storage->groupBy('provider_name');

        expect($grouped)->toHaveKey('openai');
        expect($grouped)->toHaveKey('anthropic');
        expect($grouped['openai']['total_prompt_tokens'])->toBe(250);
        expect($grouped['anthropic']['total_prompt_tokens'])->toBe(200);
    });

    it('uses correct storage prefix', function () {
        expect(UsageStorage::getStoragePrefix())->toBe('usage');
    });

    it('can get the last usage record', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        $storage->addUsage(new Usage(100, 50, 150));
        $storage->addUsage(new Usage(200, 100, 300));

        $last = $storage->getLastUsage();

        expect($last)->not->toBeNull();
        expect($last->promptTokens)->toBe(200);
    });

    it('can clear all usage records', function () {
        $identity = new SessionIdentity('TestAgent', 'chat1', 'user123');
        $storage = new UsageStorage($identity, [InMemoryStorage::class], 'gpt-4', 'openai');

        $storage->addUsage(new Usage(100, 50, 150));
        $storage->addUsage(new Usage(200, 100, 300));

        expect($storage->getUsageRecords()->count())->toBe(2);

        $storage->clear();

        expect($storage->getUsageRecords()->count())->toBe(0);
    });
});
