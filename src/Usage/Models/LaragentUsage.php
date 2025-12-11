<?php

namespace LarAgent\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for storing usage records.
 * 
 * Can be used with EloquentStorage driver for persistent usage tracking.
 */
class LaragentUsage extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'laragent_usage';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'session_key',
        'position',
        'record_id',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'agent_name',
        'user_id',
        'group',
        'chat_name',
        'model_name',
        'provider_name',
        'recorded_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'recorded_at' => 'datetime',
    ];

    /**
     * Scope to get items for a specific session.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSession($query, string $sessionKey)
    {
        return $query->where('session_key', $sessionKey);
    }

    /**
     * Scope to order by position.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Scope to filter by agent name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAgent($query, string $agentName)
    {
        return $query->where('agent_name', $agentName);
    }

    /**
     * Scope to filter by user ID.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, ?string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by model name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, string $modelName)
    {
        return $query->where('model_name', $modelName);
    }

    /**
     * Scope to filter by provider name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForProvider($query, string $providerName)
    {
        return $query->where('provider_name', $providerName);
    }

    /**
     * Scope to filter by group.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForGroup($query, ?string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope to filter by date range.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBetweenDates($query, $from, $to = null)
    {
        $query->where('recorded_at', '>=', $from);
        
        if ($to !== null) {
            $query->where('recorded_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Scope to filter by a specific date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnDate($query, $date)
    {
        return $query->whereDate('recorded_at', $date);
    }

    /**
     * Get aggregated token totals.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array
     */
    public static function aggregate($query = null)
    {
        $query = $query ?? static::query();
        
        return [
            'total_prompt_tokens' => (int) $query->sum('prompt_tokens'),
            'total_completion_tokens' => (int) $query->sum('completion_tokens'),
            'total_tokens' => (int) $query->sum('total_tokens'),
            'record_count' => $query->count(),
        ];
    }

    /**
     * Get usage grouped by a specific column.
     *
     * @param  string  $column  Column to group by
     * @param  \Illuminate\Database\Eloquent\Builder|null  $query
     * @return \Illuminate\Support\Collection
     */
    public static function groupByColumn(string $column, $query = null)
    {
        $query = $query ?? static::query();
        
        return $query->groupBy($column)
            ->selectRaw("{$column}, SUM(prompt_tokens) as total_prompt_tokens, SUM(completion_tokens) as total_completion_tokens, SUM(total_tokens) as total_tokens, COUNT(*) as record_count")
            ->get()
            ->keyBy($column);
    }
}
