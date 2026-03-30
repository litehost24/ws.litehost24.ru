<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_banners', function (Blueprint $table) {
            $table->boolean('attach_archives')->default(false)->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('site_banners', function (Blueprint $table) {
            $table->dropColumn('attach_archives');
        });
    }
};
