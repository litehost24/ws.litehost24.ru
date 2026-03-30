<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'last_seen_amnezia')) {
                $column = $table->timestamp('last_seen_amnezia')->nullable();
                if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'captured_at')) {
                    $column->after('captured_at');
                }
            }
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'last_seen_vless')) {
                $column = $table->timestamp('last_seen_vless')->nullable();
                if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'vless_captured_at')) {
                    $column->after('vless_captured_at');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'last_seen_vless')) {
                $table->dropColumn('last_seen_vless');
            }
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'last_seen_amnezia')) {
                $table->dropColumn('last_seen_amnezia');
            }
        });
    }
};
