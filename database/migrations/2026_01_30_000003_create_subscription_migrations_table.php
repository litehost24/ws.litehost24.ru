<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('batch_size')->default(100);
            $table->unsignedBigInteger('last_processed_id')->default(0);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_migrations');
    }
};
