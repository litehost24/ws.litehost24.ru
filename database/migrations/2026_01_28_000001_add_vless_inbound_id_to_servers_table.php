<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('servers')) {
            // In some test setups (e.g. sqlite in-memory) migrations may run without this table.
            return;
        }
        if (Schema::hasColumn('servers', 'vless_inbound_id')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('vless_inbound_id')->nullable()->after('url2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('servers')) {
            return;
        }
        if (!Schema::hasColumn('servers', 'vless_inbound_id')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('vless_inbound_id');
        });
    }
};
