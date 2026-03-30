<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_peer_traffic_daily', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_peer_traffic_daily', 'vless_rx_bytes_delta')) {
                $table->unsignedBigInteger('vless_rx_bytes_delta')->default(0)->after('total_bytes_delta');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_daily', 'vless_tx_bytes_delta')) {
                $table->unsignedBigInteger('vless_tx_bytes_delta')->default(0)->after('vless_rx_bytes_delta');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_daily', 'vless_total_bytes_delta')) {
                $table->unsignedBigInteger('vless_total_bytes_delta')->default(0)->after('vless_tx_bytes_delta');
            }
        });

        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_rx_bytes')) {
                $table->unsignedBigInteger('vless_rx_bytes')->nullable()->after('tx_bytes');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_tx_bytes')) {
                $table->unsignedBigInteger('vless_tx_bytes')->nullable()->after('vless_rx_bytes');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_captured_at')) {
                $table->timestamp('vless_captured_at')->nullable()->after('vless_tx_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peer_traffic_daily', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_peer_traffic_daily', 'vless_total_bytes_delta')) {
                $table->dropColumn('vless_total_bytes_delta');
            }
            if (Schema::hasColumn('vpn_peer_traffic_daily', 'vless_tx_bytes_delta')) {
                $table->dropColumn('vless_tx_bytes_delta');
            }
            if (Schema::hasColumn('vpn_peer_traffic_daily', 'vless_rx_bytes_delta')) {
                $table->dropColumn('vless_rx_bytes_delta');
            }
        });

        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_captured_at')) {
                $table->dropColumn('vless_captured_at');
            }
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_tx_bytes')) {
                $table->dropColumn('vless_tx_bytes');
            }
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_rx_bytes')) {
                $table->dropColumn('vless_rx_bytes');
            }
        });
    }
};
