<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_endpoint_networks', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_ip', 64)->unique();
            $table->unsignedInteger('as_number')->nullable();
            $table->string('as_name', 255)->nullable();
            $table->string('operator_label', 120)->nullable();
            $table->string('network_type', 32)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['network_type', 'last_checked_at'], 'vpn_endpoint_networks_type_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_endpoint_networks');
    }
};
