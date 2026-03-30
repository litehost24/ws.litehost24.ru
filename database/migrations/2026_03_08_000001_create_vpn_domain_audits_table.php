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
        Schema::create('vpn_domain_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('domain');
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('allow_vpn')->default(false);
            $table->timestamps();

            $table->unique(['server_id', 'domain']);
            $table->index(['server_id', 'allow_vpn']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_domain_audits');
    }
};
