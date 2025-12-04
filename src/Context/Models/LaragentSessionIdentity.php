<?php

namespace LarAgent\Context\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LarAgent\Context\Database\Factories\LaragentSessionIdentityFactory;

class LaragentSessionIdentity extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'laragent_session_identities';

    /**
     * The attributes that are mass assignable.
     * These match the fields from SessionIdentity DataModel.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'session_key',
        'position',
        // SessionIdentity fields
        'key',
        'agent_name',
        'chat_name',
        'user_id',
        'group',
        'scope',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return LaragentSessionIdentityFactory::new();
    }

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
}
