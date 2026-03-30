<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionMigration extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'server_id',
        'batch_size',
        'last_processed_id',
        'processed_count',
        'error_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
