<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_subscription_id',
        'app_device_id',
        'owner_user_id',
        'server_id',
        'peer_name',
        'binding_generation',
        'bound_at',
        'last_config_issued_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'last_config_issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function userSubscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class);
    }

    public function appDevice(): BelongsTo
    {
        return $this->belongsTo(AppDevice::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AppDeviceSession::class);
    }
}
