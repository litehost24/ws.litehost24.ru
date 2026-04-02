<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscriptionTopup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_subscription_id',
        'user_id',
        'topup_code',
        'name',
        'price',
        'traffic_bytes',
        'expires_on',
    ];

    protected $casts = [
        'price' => 'integer',
        'traffic_bytes' => 'integer',
        'expires_on' => 'date',
    ];

    public function userSubscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'user_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
