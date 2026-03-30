<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VpnPeerEndpointEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'user_id',
        'peer_name',
        'public_key',
        'endpoint',
        'endpoint_ip',
        'endpoint_port',
        'seen_at',
    ];

    protected $casts = [
        'endpoint_port' => 'integer',
        'seen_at' => 'datetime',
    ];
}
