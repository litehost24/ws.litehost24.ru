<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportChat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'last_message_at',
        'last_read_by_user_at',
        'last_read_by_admin_at',
        'notified_admins_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_read_by_user_at' => 'datetime',
        'last_read_by_admin_at' => 'datetime',
        'notified_admins_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportChatMessage::class);
    }
}
