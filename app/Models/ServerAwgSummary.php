<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerAwgSummary extends Model
{
    protected $fillable = [
        'server_id',
        'collected_at',
        'window_sec',
        'peers_total',
        'peers_with_endpoint',
        'peers_active_5m',
        'peers_active_60s',
        'peers_transferring',
        'total_rx_mbps',
        'total_tx_mbps',
        'total_mbps',
        'avg_mbps_per_endpoint',
        'avg_mbps_per_active_5m',
        'heavy_peers_count',
        'top_peer_name',
        'top_peer_user_id',
        'top_peer_ip',
        'top_peer_mbps',
        'top_peer_share_percent',
        'top_peers',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'window_sec' => 'integer',
        'peers_total' => 'integer',
        'peers_with_endpoint' => 'integer',
        'peers_active_5m' => 'integer',
        'peers_active_60s' => 'integer',
        'peers_transferring' => 'integer',
        'heavy_peers_count' => 'integer',
        'top_peer_user_id' => 'integer',
        'top_peers' => 'array',
        'total_rx_mbps' => 'decimal:2',
        'total_tx_mbps' => 'decimal:2',
        'total_mbps' => 'decimal:2',
        'avg_mbps_per_endpoint' => 'decimal:2',
        'avg_mbps_per_active_5m' => 'decimal:2',
        'top_peer_mbps' => 'decimal:2',
        'top_peer_share_percent' => 'decimal:2',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
