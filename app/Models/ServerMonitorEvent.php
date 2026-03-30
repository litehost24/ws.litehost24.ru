<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMonitorEvent extends Model
{
    protected $fillable = [
        'server_id',
        'node',
        'status',
        'changed_at',
        'host',
        'port',
        'ping_ok',
        'tcp_ok',
        'error_message',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'ping_ok' => 'boolean',
        'tcp_ok' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}

