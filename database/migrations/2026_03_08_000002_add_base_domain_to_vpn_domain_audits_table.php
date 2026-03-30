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
        Schema::table('vpn_domain_audits', function (Blueprint $table) {
            $table->string('base_domain')->nullable()->after('domain');
            $table->index(['server_id', 'base_domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_domain_audits', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'base_domain']);
            $table->dropColumn('base_domain');
        });
    }
};
