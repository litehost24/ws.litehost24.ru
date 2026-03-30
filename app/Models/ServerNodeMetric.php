<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerNodeMetric extends Model
{
    protected $fillable = [
        'server_id',
        'node',
        'ok',
        'error_message',
        'collected_at',
        'load1',
        'load5',
        'load15',
        'cpu_usage_percent',
        'cpu_iowait_percent',
        'memory_used_percent',
        'memory_total_bytes',
        'memory_used_bytes',
        'counters',
        'rates',
    ];

    protected $casts = [
        'ok' => 'boolean',
        'collected_at' => 'datetime',
        'counters' => 'array',
        'rates' => 'array',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
