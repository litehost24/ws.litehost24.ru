<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnDomainBaseProbe extends Model
{
    protected $fillable = [
        'server_id',
        'base_domain',
        'status',
        'latency_ms',
        'http_code',
        'error',
        'attempts',
        'fail_streak',
        'checked_at',
    ];

    protected $casts = [
        'latency_ms' => 'integer',
        'http_code' => 'integer',
        'attempts' => 'integer',
        'fail_streak' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
