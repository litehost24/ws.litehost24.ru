<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VpnPeerServerState extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'user_id',
        'peer_name',
        'public_key',
        'ip',
        'endpoint',
        'endpoint_ip',
        'endpoint_port',
        'server_status',
        'last_handshake_epoch',
        'status_fetched_at',
    ];

    protected $casts = [
        'endpoint_port' => 'integer',
        'last_handshake_epoch' => 'integer',
        'status_fetched_at' => 'datetime',
    ];
}
