<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->unsignedBigInteger('pending_ref_user_id')->nullable()->after('user_id');
            $table->foreign('pending_ref_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->dropForeign(['pending_ref_user_id']);
            $table->dropColumn('pending_ref_user_id');
        });
    }
};

