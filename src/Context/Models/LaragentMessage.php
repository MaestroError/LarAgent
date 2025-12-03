<?php

namespace LarAgent\Context\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LarAgent\Context\Database\Factories\LaragentMessageFactory;

class LaragentMessage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'laragent_messages';

    /**
     * The attributes that are mass assignable.
     * These match the fields from Message DataModel.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'session_key',
        'position',
        // Core message fields
        'role',
        'content',
        'message_uuid',
        'message_created',
        // Tool-related fields
        'tool_calls',
        'tool_call_id',
        // Usage statistics
        'usage',
        // Additional data
        'metadata',
        'extras',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'tool_calls' => 'array',
        'usage' => 'array',
        'metadata' => 'array',
        'extras' => 'array',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return LaragentMessageFactory::new();
    }

    /**
     * Scope to get items for a specific session.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $sessionKey
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSession($query, string $sessionKey)
    {
        return $query->where('session_key', $sessionKey);
    }

    /**
     * Scope to order by position.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }
}
