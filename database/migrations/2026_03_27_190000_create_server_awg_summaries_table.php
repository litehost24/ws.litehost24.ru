<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_awg_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->timestamp('collected_at')->nullable();
            $table->unsignedInteger('window_sec')->default(0);
            $table->unsignedInteger('peers_total')->default(0);
            $table->unsignedInteger('peers_with_endpoint')->default(0);
            $table->unsignedInteger('peers_active_5m')->default(0);
            $table->unsignedInteger('peers_active_60s')->default(0);
            $table->unsignedInteger('peers_transferring')->default(0);
            $table->decimal('total_rx_mbps', 10, 2)->default(0);
            $table->decimal('total_tx_mbps', 10, 2)->default(0);
            $table->decimal('total_mbps', 10, 2)->default(0);
            $table->decimal('avg_mbps_per_endpoint', 10, 2)->default(0);
            $table->decimal('avg_mbps_per_active_5m', 10, 2)->default(0);
            $table->unsignedInteger('heavy_peers_count')->default(0);
            $table->string('top_peer_name')->nullable();
            $table->unsignedBigInteger('top_peer_user_id')->nullable();
            $table->string('top_peer_ip')->nullable();
            $table->decimal('top_peer_mbps', 10, 2)->nullable();
            $table->decimal('top_peer_share_percent', 5, 2)->nullable();
            $table->json('top_peers')->nullable();
            $table->timestamps();

            $table->unique('server_id', 'server_awg_summaries_server_id_uq');
            $table->index('collected_at', 'server_awg_summaries_collected_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_awg_summaries');
    }
};
