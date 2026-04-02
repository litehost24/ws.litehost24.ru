<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_subscriptions', 'next_vpn_plan_code')) {
                $table->string('next_vpn_plan_code', 64)
                    ->nullable()
                    ->after('vpn_traffic_limit_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('user_subscriptions', 'next_vpn_plan_code')) {
                $table->dropColumn('next_vpn_plan_code');
            }
        });
    }
};
