<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class AppDeviceSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_device_id',
        'subscription_access_id',
        'personal_access_token_id',
        'last_seen_at',
        'expires_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder) {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function appDevice(): BelongsTo
    {
        return $this->belongsTo(AppDevice::class);
    }

    public function subscriptionAccess(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAccess::class);
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
