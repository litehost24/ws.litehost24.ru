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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');

            $table->string('role')->default('')->after('email');
            $table->unsignedBigInteger('ref_user_id')->default(0)->after('id');
            $table->string('ref_link')->default('')->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');

            $table->dropColumn('role');
            $table->dropColumn('ref_link');
        });
    }
};
