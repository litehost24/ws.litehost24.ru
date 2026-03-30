<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_read_by_user_at')->nullable();
            $table->timestamp('last_read_by_admin_at')->nullable();
            $table->timestamp('notified_admins_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chats');
    }
};
