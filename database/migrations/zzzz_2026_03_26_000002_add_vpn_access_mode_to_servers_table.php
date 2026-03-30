<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('servers') || Schema::hasColumn('servers', 'vpn_access_mode')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->string('vpn_access_mode', 32)
                ->default(Server::VPN_ACCESS_WHITE_IP)
                ->after('node1_api_enabled');
            $table->index('vpn_access_mode');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'vpn_access_mode')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['vpn_access_mode']);
            $table->dropColumn('vpn_access_mode');
        });
    }
};
