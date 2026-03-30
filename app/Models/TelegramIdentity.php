<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $pending_ref_user_id
 * @property int $telegram_user_id
 * @property int $telegram_chat_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property int $last_update_id
 * @property array|null $state
 * @property \Illuminate\Support\Carbon|null $state_expires_at
 * @property \Illuminate\Support\Carbon|null $last_rebill_warned_at
 */
class TelegramIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'pending_ref_user_id',
        'telegram_user_id',
        'telegram_chat_id',
        'username',
        'first_name',
        'last_name',
        'last_update_id',
        'state',
        'state_expires_at',
        'last_rebill_warned_at',
    ];

    protected $casts = [
        'state' => 'array',
        'state_expires_at' => 'datetime',
        'last_rebill_warned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
