<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('referral_id');
            $table->unsignedBigInteger('user_subscription_id');
            $table->string('service_key');
            $table->unsignedInteger('base_price_cents');
            $table->unsignedInteger('markup_cents');
            $table->unsignedTinyInteger('project_cut_pct');
            $table->unsignedInteger('project_cut_cents');
            $table->unsignedInteger('partner_earn_cents');
            $table->timestamps();

            $table->unique(['user_subscription_id'], 'referral_earnings_user_subscription_unique');
            $table->index(['referrer_id', 'created_at'], 'referral_earnings_referrer_created');
            $table->index(['referral_id', 'created_at'], 'referral_earnings_referral_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_earnings');
    }
};
