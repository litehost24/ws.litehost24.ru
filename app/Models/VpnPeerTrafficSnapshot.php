<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VpnPeerTrafficSnapshot extends Model
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
        'rx_bytes',
        'tx_bytes',
        'captured_at',
        'vless_rx_bytes',
        'vless_tx_bytes',
        'vless_captured_at',
        'last_seen_amnezia',
        'last_seen_vless',
    ];

    protected $casts = [
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'endpoint_port' => 'integer',
        'captured_at' => 'datetime',
        'vless_rx_bytes' => 'integer',
        'vless_tx_bytes' => 'integer',
        'vless_captured_at' => 'datetime',
        'last_seen_amnezia' => 'datetime',
        'last_seen_vless' => 'datetime',
    ];
}
