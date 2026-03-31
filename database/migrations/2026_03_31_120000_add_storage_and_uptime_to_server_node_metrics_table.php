<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_node_metrics', function (Blueprint $table) {
            if (!Schema::hasColumn('server_node_metrics', 'uptime_seconds')) {
                $table->unsignedBigInteger('uptime_seconds')->nullable()->after('collected_at');
            }

            if (!Schema::hasColumn('server_node_metrics', 'swap_used_percent')) {
                $table->decimal('swap_used_percent', 5, 2)->nullable()->after('memory_used_bytes');
            }

            if (!Schema::hasColumn('server_node_metrics', 'swap_total_bytes')) {
                $table->unsignedBigInteger('swap_total_bytes')->nullable()->after('swap_used_percent');
            }

            if (!Schema::hasColumn('server_node_metrics', 'swap_used_bytes')) {
                $table->unsignedBigInteger('swap_used_bytes')->nullable()->after('swap_total_bytes');
            }

            if (!Schema::hasColumn('server_node_metrics', 'disk_used_percent')) {
                $table->decimal('disk_used_percent', 5, 2)->nullable()->after('swap_used_bytes');
            }

            if (!Schema::hasColumn('server_node_metrics', 'disk_total_bytes')) {
                $table->unsignedBigInteger('disk_total_bytes')->nullable()->after('disk_used_percent');
            }

            if (!Schema::hasColumn('server_node_metrics', 'disk_used_bytes')) {
                $table->unsignedBigInteger('disk_used_bytes')->nullable()->after('disk_total_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('server_node_metrics', function (Blueprint $table) {
            $drop = [];

            foreach ([
                'uptime_seconds',
                'swap_used_percent',
                'swap_total_bytes',
                'swap_used_bytes',
                'disk_used_percent',
                'disk_total_bytes',
                'disk_used_bytes',
            ] as $column) {
                if (Schema::hasColumn('server_node_metrics', $column)) {
                    $drop[] = $column;
                }
            }

            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
