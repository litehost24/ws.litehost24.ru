<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_price_defaults', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->string('service_key');
            $table->unsignedInteger('markup_cents')->default(0);
            $table->timestamps();

            $table->unique(['referrer_id', 'service_key'], 'partner_price_defaults_unique');
            $table->index(['referrer_id', 'service_key'], 'partner_price_defaults_referrer_service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_price_defaults');
    }
};
