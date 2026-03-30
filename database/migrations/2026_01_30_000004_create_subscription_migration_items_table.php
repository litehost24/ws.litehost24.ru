<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_migration_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_migration_id');
            $table->unsignedBigInteger('user_subscription_id');
            $table->string('status', 20)->default('error');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['subscription_migration_id', 'user_subscription_id'], 'migration_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_migration_items');
    }
};
