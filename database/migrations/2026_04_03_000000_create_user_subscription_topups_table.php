<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_subscription_topups')) {
            return;
        }

        Schema::create('user_subscription_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_subscription_id')->constrained('user_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('topup_code', 64);
            $table->string('name', 128);
            $table->unsignedInteger('price');
            $table->unsignedBigInteger('traffic_bytes');
            $table->date('expires_on')->nullable()->index();
            $table->timestamps();

            $table->index(['user_subscription_id', 'created_at'], 'user_sub_topups_sub_created_idx');
            $table->index(['user_id', 'created_at'], 'user_sub_topups_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscription_topups');
    }
};
