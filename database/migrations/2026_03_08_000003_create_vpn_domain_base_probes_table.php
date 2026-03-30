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
        Schema::create('vpn_domain_base_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('base_domain');
            $table->string('status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->string('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'base_domain']);
            $table->index(['server_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_domain_base_probes');
    }
};
