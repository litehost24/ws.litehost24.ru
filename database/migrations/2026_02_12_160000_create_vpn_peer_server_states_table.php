<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_peer_server_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('peer_name', 191);
            $table->string('public_key', 191)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('server_status', 16)->default('unknown');
            $table->unsignedBigInteger('last_handshake_epoch')->nullable();
            $table->timestamp('status_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'peer_name'], 'vpn_peer_server_states_srv_name_uq');
            $table->index(['server_id', 'server_status'], 'vpn_peer_server_states_srv_status_idx');
            $table->index(['server_id', 'status_fetched_at'], 'vpn_peer_server_states_srv_fetched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peer_server_states');
    }
};
