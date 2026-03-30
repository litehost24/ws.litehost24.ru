<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_price_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('referral_id');
            $table->string('service_key');
            $table->unsignedInteger('markup_cents')->default(0);
            $table->timestamps();

            $table->unique(['referrer_id', 'referral_id', 'service_key'], 'referral_price_rules_unique');
            $table->index(['referrer_id', 'service_key'], 'referral_price_rules_referrer_service');
            $table->index(['referral_id', 'service_key'], 'referral_price_rules_referral_service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_price_rules');
    }
};
