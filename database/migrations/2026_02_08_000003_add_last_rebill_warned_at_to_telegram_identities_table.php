<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->timestamp('last_rebill_warned_at')->nullable()->after('state_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->dropColumn('last_rebill_warned_at');
        });
    }
};

