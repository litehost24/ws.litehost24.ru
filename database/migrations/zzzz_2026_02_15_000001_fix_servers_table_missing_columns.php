<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            // NOTE: Migration ordering in this repo is non-standard: create_servers_table.php
            // sorts after timestamped migrations. Some add-column migrations early-return
            // when the table doesn't exist, so new installs can miss columns.

            if (!Schema::hasColumn('servers', 'node1_api_url')) {
                $table->string('node1_api_url')->nullable()->after('url1');
            }
            if (!Schema::hasColumn('servers', 'node1_api_ca_path')) {
                $table->string('node1_api_ca_path')->nullable()->after('node1_api_url');
            }
            if (!Schema::hasColumn('servers', 'node1_api_cert_path')) {
                $table->string('node1_api_cert_path')->nullable()->after('node1_api_ca_path');
            }
            if (!Schema::hasColumn('servers', 'node1_api_key_path')) {
                $table->string('node1_api_key_path')->nullable()->after('node1_api_cert_path');
            }
            if (!Schema::hasColumn('servers', 'node1_api_enabled')) {
                $table->boolean('node1_api_enabled')->default(false)->after('node1_api_key_path');
            }

            if (!Schema::hasColumn('servers', 'vless_inbound_id')) {
                $table->unsignedInteger('vless_inbound_id')->nullable()->after('url2');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: do not drop columns.
    }
};

