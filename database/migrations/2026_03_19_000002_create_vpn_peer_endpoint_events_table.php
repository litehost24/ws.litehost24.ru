<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_peer_endpoint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('peer_name', 191);
            $table->string('public_key', 255)->nullable();
            $table->string('endpoint', 191);
            $table->string('endpoint_ip', 64)->nullable();
            $table->unsignedInteger('endpoint_port')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->index(['server_id', 'peer_name', 'seen_at'], 'vpn_peer_endpoint_events_srv_peer_seen_idx');
            $table->index(['user_id', 'seen_at'], 'vpn_peer_endpoint_events_user_seen_idx');
            $table->index(['public_key', 'seen_at'], 'vpn_peer_endpoint_events_pub_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peer_endpoint_events');
    }
};
