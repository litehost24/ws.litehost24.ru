<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnDomainAudit extends Model
{
    protected $fillable = [
        'server_id',
        'domain',
        'base_domain',
        'count',
        'last_seen_at',
        'allow_vpn',
    ];

    protected $casts = [
        'count' => 'integer',
        'last_seen_at' => 'datetime',
        'allow_vpn' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
