<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_peer_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('peer_name', 191);
            $table->string('public_key', 255)->nullable();
            $table->string('ip', 64)->nullable();
            $table->unsignedBigInteger('rx_bytes_delta')->default(0);
            $table->unsignedBigInteger('tx_bytes_delta')->default(0);
            $table->unsignedBigInteger('total_bytes_delta')->default(0);
            $table->timestamps();

            $table->unique(['date', 'server_id', 'peer_name'], 'vpn_peer_traffic_daily_date_srv_name_uq');
            $table->index(['server_id', 'date'], 'vpn_peer_traffic_daily_srv_date_idx');
            $table->index(['user_id', 'date'], 'vpn_peer_traffic_daily_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peer_traffic_daily');
    }
};

