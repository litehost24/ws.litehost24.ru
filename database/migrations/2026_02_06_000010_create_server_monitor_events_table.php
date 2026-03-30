<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_monitor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('node', 16); // node1 | node2
            $table->string('status', 8); // up | down
            $table->dateTime('changed_at');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->boolean('ping_ok')->default(false);
            $table->boolean('tcp_ok')->default(false);
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'node', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_monitor_events');
    }
};

