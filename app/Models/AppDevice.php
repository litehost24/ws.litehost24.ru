<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_uuid',
        'platform',
        'device_name',
        'app_version',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function subscriptionAccesses(): HasMany
    {
        return $this->hasMany(SubscriptionAccess::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AppDeviceSession::class);
    }
}
