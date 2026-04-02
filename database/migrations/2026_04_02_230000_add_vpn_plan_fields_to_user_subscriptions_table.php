<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_subscriptions')) {
            return;
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_subscriptions', 'vpn_plan_code')) {
                $table->string('vpn_plan_code', 64)->nullable()->after('vpn_access_mode')->index();
            }

            if (!Schema::hasColumn('user_subscriptions', 'vpn_plan_name')) {
                $table->string('vpn_plan_name', 128)->nullable()->after('vpn_plan_code');
            }

            if (!Schema::hasColumn('user_subscriptions', 'vpn_traffic_limit_bytes')) {
                $table->unsignedBigInteger('vpn_traffic_limit_bytes')->nullable()->after('vpn_plan_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_subscriptions')) {
            return;
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('user_subscriptions', 'vpn_traffic_limit_bytes')) {
                $table->dropColumn('vpn_traffic_limit_bytes');
            }

            if (Schema::hasColumn('user_subscriptions', 'vpn_plan_name')) {
                $table->dropColumn('vpn_plan_name');
            }

            if (Schema::hasColumn('user_subscriptions', 'vpn_plan_code')) {
                $table->dropColumn('vpn_plan_code');
            }
        });
    }
};
