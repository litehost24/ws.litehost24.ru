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
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('dual_protocol_strikes')->default(0)->after('vless_blocked_until');
            $table->timestamp('dual_protocol_last_seen_at')->nullable()->after('dual_protocol_strikes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('dual_protocol_last_seen_at');
            $table->dropColumn('dual_protocol_strikes');
        });
    }
};
