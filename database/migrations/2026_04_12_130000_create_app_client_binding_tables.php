<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid', 128)->unique();
            $table->string('platform', 32);
            $table->string('device_name')->nullable();
            $table->string('app_version', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_subscription_id')->constrained('user_subscriptions')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('app_device_id')->nullable()->constrained('app_devices')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_subscription_id', 'expires_at']);
        });

        Schema::create('subscription_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_subscription_id')->constrained('user_subscriptions')->cascadeOnDelete();
            $table->foreignId('app_device_id')->constrained('app_devices')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('peer_name', 191)->nullable();
            $table->unsignedInteger('binding_generation')->default(1);
            $table->timestamp('bound_at');
            $table->timestamp('last_config_issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['user_subscription_id', 'revoked_at']);
            $table->index(['app_device_id', 'revoked_at']);
        });

        Schema::create('app_device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_device_id')->constrained('app_devices')->cascadeOnDelete();
            $table->foreignId('subscription_access_id')->constrained('subscription_accesses')->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();

            $table->unique('personal_access_token_id');
            $table->index(['subscription_access_id', 'revoked_at']);
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('app_config_version')->default(0)->after('pending_vpn_access_mode_error');
            $table->timestamp('app_config_updated_at')->nullable()->after('app_config_version');
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['app_config_version', 'app_config_updated_at']);
        });

        Schema::dropIfExists('app_device_sessions');
        Schema::dropIfExists('subscription_accesses');
        Schema::dropIfExists('subscription_invites');
        Schema::dropIfExists('app_devices');
    }
};
