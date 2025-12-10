<?php

namespace LarAgent\Usage\Models;

use Illuminate\Database\Eloquent\Model;

class LaragentUsage extends Model
{
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
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'user_id',
        'group',
        'chat_name',
        'model',
        'provider',
        'agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope to filter by user ID
     */
    public function scopeByUserId($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by model
     */
    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    /**
     * Scope to filter by provider
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to filter by agent
     */
    public function scopeByAgent($query, string $agent)
    {
        return $query->where('agent', $agent);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeByDateRange($query, ?string $startDate = null, ?string $endDate = null)
    {
        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Get total usage aggregated
     */
    public static function getTotalUsage($query = null)
    {
        $query = $query ?? static::query();

        return [
            'prompt_tokens' => $query->sum('prompt_tokens'),
            'completion_tokens' => $query->sum('completion_tokens'),
            'total_tokens' => $query->sum('total_tokens'),
        ];
    }
}
