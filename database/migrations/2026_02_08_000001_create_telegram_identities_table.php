<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_identities', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->bigInteger('telegram_chat_id');

            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Idempotency for webhook retries.
            $table->unsignedBigInteger('last_update_id')->default(0);

            // Optional conversational state (top-up amount, etc).
            $table->json('state')->nullable();
            $table->timestamp('state_expires_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_identities');
    }
};

