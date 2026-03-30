<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnDomainProbeJob extends Model
{
    protected $fillable = [
        'server_id',
        'limit',
        'days',
        'fresh_hours',
        'status',
        'attempts',
        'requested_by',
        'requested_at',
        'started_at',
        'finished_at',
        'output',
        'error',
    ];

    protected $casts = [
        'limit' => 'integer',
        'days' => 'integer',
        'fresh_hours' => 'integer',
        'attempts' => 'integer',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
