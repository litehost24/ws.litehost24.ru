<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_subscriptions')) {
            return;
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_subscriptions', 'pending_vpn_access_mode_source_server_id')) {
                $table->unsignedBigInteger('pending_vpn_access_mode_source_server_id')
                    ->nullable()
                    ->after('vpn_access_mode');
            }

            if (!Schema::hasColumn('user_subscriptions', 'pending_vpn_access_mode_source_peer_name')) {
                $table->string('pending_vpn_access_mode_source_peer_name')
                    ->nullable()
                    ->after('pending_vpn_access_mode_source_server_id');
            }

            if (!Schema::hasColumn('user_subscriptions', 'pending_vpn_access_mode_disconnect_at')) {
                $table->timestamp('pending_vpn_access_mode_disconnect_at')
                    ->nullable()
                    ->after('pending_vpn_access_mode_source_peer_name');
            }

            if (!Schema::hasColumn('user_subscriptions', 'pending_vpn_access_mode_error')) {
                $table->text('pending_vpn_access_mode_error')
                    ->nullable()
                    ->after('pending_vpn_access_mode_disconnect_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_subscriptions')) {
            return;
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $columns = [];

            foreach ([
                'pending_vpn_access_mode_source_server_id',
                'pending_vpn_access_mode_source_peer_name',
                'pending_vpn_access_mode_disconnect_at',
                'pending_vpn_access_mode_error',
            ] as $column) {
                if (Schema::hasColumn('user_subscriptions', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
