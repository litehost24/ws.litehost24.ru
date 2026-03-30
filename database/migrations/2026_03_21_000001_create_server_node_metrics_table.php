<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_node_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('node', 32);
            $table->boolean('ok')->default(false);
            $table->string('error_message')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->decimal('load1', 8, 2)->nullable();
            $table->decimal('load5', 8, 2)->nullable();
            $table->decimal('load15', 8, 2)->nullable();
            $table->decimal('cpu_usage_percent', 5, 2)->nullable();
            $table->decimal('cpu_iowait_percent', 5, 2)->nullable();
            $table->decimal('memory_used_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->json('counters')->nullable();
            $table->json('rates')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'node']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_node_metrics');
    }
};
