<?php

namespace LarAgent\Usage\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

/**
 * Array container for UsageRecord DataModels.
 *
 * Provides filtering and aggregation methods for usage analytics.
 */
class UsageArray extends DataModelArray
{
    /**
     * Return the list of allowed DataModel classes.
     */
    public static function allowedModels(): array
    {
        return [UsageRecord::class];
    }

    /**
     * Return the discriminator field name.
     * Not used for UsageArray since it only allows one type.
     */
    public function discriminator(): string
    {
        return 'recordId';
    }

    /**
     * Filter usage records by agent name.
     */
    public function filterByAgent(string $agentName): static
    {
        return $this->filter(fn (UsageRecord $record) => $record->agentName === $agentName);
    }

    /**
     * Filter usage records by user ID.
     */
    public function filterByUser(?string $userId): static
    {
        return $this->filter(fn (UsageRecord $record) => $record->userId === $userId);
    }

    /**
     * Filter usage records by group.
     */
    public function filterByGroup(?string $group): static
    {
        return $this->filter(fn (UsageRecord $record) => $record->group === $group);
    }

    /**
     * Filter usage records by model name.
     */
    public function filterByModel(string $modelName): static
    {
        return $this->filter(fn (UsageRecord $record) => $record->modelName === $modelName);
    }

    /**
     * Filter usage records by provider name.
     */
    public function filterByProvider(string $providerName): static
    {
        return $this->filter(fn (UsageRecord $record) => $record->providerName === $providerName);
    }

    /**
     * Filter usage records by date range.
     *
     * @param  \DateTimeInterface|string  $from  Start date (inclusive)
     * @param  \DateTimeInterface|string|null  $to  End date (inclusive), defaults to now
     */
    public function filterByDateRange($from, $to = null): static
    {
        $fromDate = $from instanceof \DateTimeInterface ? $from : new \DateTimeImmutable($from);
        $toDate = $to instanceof \DateTimeInterface ? $to : ($to !== null ? new \DateTimeImmutable($to) : new \DateTimeImmutable);

        return $this->filter(function (UsageRecord $record) use ($fromDate, $toDate) {
            $recordDate = $record->getRecordedAtDateTime();

            return $recordDate >= $fromDate && $recordDate <= $toDate;
        });
    }

    /**
     * Filter usage records created on a specific date.
     *
     * @param  \DateTimeInterface|string  $date  The date to filter by
     */
    public function filterByDate($date): static
    {
        $targetDate = $date instanceof \DateTimeImmutable
            ? $date
            : ($date instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($date)
                : new \DateTimeImmutable($date));
        $startOfDay = $targetDate->setTime(0, 0, 0);
        $endOfDay = $targetDate->setTime(23, 59, 59);

        return $this->filterByDateRange($startOfDay, $endOfDay);
    }

    /**
     * Get total prompt tokens.
     */
    public function getTotalPromptTokens(): int
    {
        $total = 0;
        foreach ($this->items as $record) {
            if ($record instanceof UsageRecord) {
                $total += $record->promptTokens;
            }
        }

        return $total;
    }

    /**
     * Get total completion tokens.
     */
    public function getTotalCompletionTokens(): int
    {
        $total = 0;
        foreach ($this->items as $record) {
            if ($record instanceof UsageRecord) {
                $total += $record->completionTokens;
            }
        }

        return $total;
    }

    /**
     * Get total tokens (prompt + completion).
     */
    public function getTotalTokens(): int
    {
        $total = 0;
        foreach ($this->items as $record) {
            if ($record instanceof UsageRecord) {
                $total += $record->totalTokens;
            }
        }

        return $total;
    }

    /**
     * Get aggregated usage as a summary array.
     */
    public function aggregate(): array
    {
        return [
            'total_prompt_tokens' => $this->getTotalPromptTokens(),
            'total_completion_tokens' => $this->getTotalCompletionTokens(),
            'total_tokens' => $this->getTotalTokens(),
            'record_count' => $this->count(),
        ];
    }

    /**
     * Group usage records by a field and aggregate.
     *
     * @param  string  $field  Field to group by (agent_name, user_id, model_name, provider_name)
     * @return array<string, array> Aggregated results grouped by the field value
     */
    public function groupBy(string $field): array
    {
        $groups = [];
        $propertyMap = [
            'agent_name' => 'agentName',
            'user_id' => 'userId',
            'model_name' => 'modelName',
            'provider_name' => 'providerName',
            'group' => 'group',
            'chat_name' => 'chatName',
        ];

        $property = $propertyMap[$field] ?? $field;

        foreach ($this->items as $record) {
            if (! $record instanceof UsageRecord) {
                continue;
            }
            $key = $record->{$property} ?? 'unknown';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'total_prompt_tokens' => 0,
                    'total_completion_tokens' => 0,
                    'total_tokens' => 0,
                    'record_count' => 0,
                ];
            }
            $groups[$key]['total_prompt_tokens'] += $record->promptTokens;
            $groups[$key]['total_completion_tokens'] += $record->completionTokens;
            $groups[$key]['total_tokens'] += $record->totalTokens;
            $groups[$key]['record_count']++;
        }

        return $groups;
    }

    /**
     * Get usage summary as a Usage DataModel (for compatibility).
     */
    public function toUsage(): Usage
    {
        return new Usage(
            $this->getTotalPromptTokens(),
            $this->getTotalCompletionTokens(),
            $this->getTotalTokens()
        );
    }
}
