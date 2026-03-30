<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_peer_traffic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('peer_name', 191);
            $table->string('public_key', 255)->nullable();
            $table->string('ip', 64)->nullable();
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->unique(['server_id', 'peer_name'], 'vpn_peer_traffic_snapshots_srv_name_uq');
            $table->index(['server_id', 'captured_at'], 'vpn_peer_traffic_snapshots_srv_captured_idx');
            $table->index(['user_id', 'captured_at'], 'vpn_peer_traffic_snapshots_user_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peer_traffic_snapshots');
    }
};

