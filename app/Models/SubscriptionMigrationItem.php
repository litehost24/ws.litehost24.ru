<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionMigrationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_migration_id',
        'user_subscription_id',
        'status',
        'error',
    ];
}
