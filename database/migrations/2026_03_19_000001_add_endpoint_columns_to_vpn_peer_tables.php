<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint')) {
                $table->string('endpoint', 191)->nullable()->after('ip');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint_ip')) {
                $table->string('endpoint_ip', 64)->nullable()->after('endpoint');
            }
            if (!Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint_port')) {
                $table->unsignedInteger('endpoint_port')->nullable()->after('endpoint_ip');
            }
        });

        Schema::table('vpn_peer_server_states', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_peer_server_states', 'endpoint')) {
                $table->string('endpoint', 191)->nullable()->after('ip');
            }
            if (!Schema::hasColumn('vpn_peer_server_states', 'endpoint_ip')) {
                $table->string('endpoint_ip', 64)->nullable()->after('endpoint');
            }
            if (!Schema::hasColumn('vpn_peer_server_states', 'endpoint_port')) {
                $table->unsignedInteger('endpoint_port')->nullable()->after('endpoint_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peer_server_states', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_peer_server_states', 'endpoint_port')) {
                $table->dropColumn('endpoint_port');
            }
            if (Schema::hasColumn('vpn_peer_server_states', 'endpoint_ip')) {
                $table->dropColumn('endpoint_ip');
            }
            if (Schema::hasColumn('vpn_peer_server_states', 'endpoint')) {
                $table->dropColumn('endpoint');
            }
        });

        Schema::table('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint_port')) {
                $table->dropColumn('endpoint_port');
            }
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint_ip')) {
                $table->dropColumn('endpoint_ip');
            }
            if (Schema::hasColumn('vpn_peer_traffic_snapshots', 'endpoint')) {
                $table->dropColumn('endpoint');
            }
        });
    }
};
