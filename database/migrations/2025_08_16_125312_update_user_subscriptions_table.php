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
            $table->string('action')->after('price');
            $table->boolean('is_processed')->default(false)->after('action');
            $table->string('file_path')->nullable()->after('end_date');

            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('action');
            $table->dropColumn('is_processed');
            $table->dropColumn('file_path');

            $table->string('name')->after('user_id');
        });
    }
};
