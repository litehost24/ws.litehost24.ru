<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_settings')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `project_settings` MODIFY `value` TEXT NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE project_settings ALTER COLUMN value TYPE TEXT');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE project_settings ALTER COLUMN value NVARCHAR(MAX) NOT NULL');
            return;
        }

        // SQLite does not enforce VARCHAR length, so no-op.
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_settings')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('UPDATE `project_settings` SET `value` = LEFT(`value`, 255) WHERE CHAR_LENGTH(`value`) > 255');
            DB::statement('ALTER TABLE `project_settings` MODIFY `value` VARCHAR(255) NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("UPDATE project_settings SET value = substring(value from 1 for 255) WHERE char_length(value) > 255");
            DB::statement('ALTER TABLE project_settings ALTER COLUMN value TYPE VARCHAR(255)');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement("UPDATE project_settings SET value = LEFT(value, 255) WHERE LEN(value) > 255");
            DB::statement('ALTER TABLE project_settings ALTER COLUMN value NVARCHAR(255) NOT NULL');
            return;
        }

        // SQLite no-op.
    }
};
