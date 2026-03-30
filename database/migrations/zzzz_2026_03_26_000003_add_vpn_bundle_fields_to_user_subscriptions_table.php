<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_subscriptions', 'server_id')) {
                $table->unsignedBigInteger('server_id')->nullable()->after('connection_config')->index();
            }

            if (!Schema::hasColumn('user_subscriptions', 'vpn_access_mode')) {
                $table->string('vpn_access_mode', 32)->nullable()->after('server_id')->index();
            }
        });

        if (!Schema::hasTable('user_subscriptions') || !Schema::hasTable('servers')) {
            return;
        }

        $serverModes = DB::table('servers')->pluck('vpn_access_mode', 'id');

        DB::table('user_subscriptions')
            ->select(['id', 'file_path'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($serverModes) {
                foreach ($rows as $row) {
                    $filePath = trim((string) ($row->file_path ?? ''));
                    if ($filePath === '') {
                        continue;
                    }

                    $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
                    if ($base === '') {
                        continue;
                    }

                    $parts = explode('_', $base);
                    if (count($parts) < 3) {
                        continue;
                    }

                    $serverId = (int) ($parts[2] ?? 0);
                    if ($serverId <= 0) {
                        continue;
                    }

                    $update = [
                        'server_id' => $serverId,
                    ];

                    $mode = trim((string) ($serverModes[$serverId] ?? ''));
                    if ($mode !== '') {
                        $update['vpn_access_mode'] = $mode;
                    }

                    DB::table('user_subscriptions')
                        ->where('id', (int) $row->id)
                        ->update($update);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('user_subscriptions', 'vpn_access_mode')) {
                $table->dropColumn('vpn_access_mode');
            }

            if (Schema::hasColumn('user_subscriptions', 'server_id')) {
                $table->dropColumn('server_id');
            }
        });
    }
};
