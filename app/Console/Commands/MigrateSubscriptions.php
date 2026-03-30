<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\SubscriptionMigration;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\components\SubscriptionPackageBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateSubscriptions extends Command
{
    protected $signature = 'subscriptions:migrate {--batch=100} {--migration_id=} {--server-id=} {--dry-run} {--only-running}';
    protected $description = 'Rebuild subscription archives/configs for the selected server in batches.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $migrationId = $this->option('migration_id');
        $dryRun = (bool) $this->option('dry-run');
        $onlyRunning = (bool) $this->option('only-running');
        $requestedServerId = $this->option('server-id');

        $migration = null;
        if ($migrationId) {
            $migration = SubscriptionMigration::find($migrationId);
        }
        if (!$migration) {
            $migration = SubscriptionMigration::where('status', 'running')->orderBy('id', 'desc')->first();
        }
        if (!$migration && $onlyRunning) {
            $this->info('No running migration.');
            return 0;
        }

        if (!$migration) {
            $migration = SubscriptionMigration::create([
                'status' => 'running',
                'batch_size' => $batchSize > 0 ? $batchSize : 100,
                'started_at' => Carbon::now(),
                'server_id' => $requestedServerId ? (int) $requestedServerId : null,
            ]);
        }

        $batchSize = (int) $migration->batch_size;

        $serverId = $migration->server_id;
        if (!$serverId && $requestedServerId) {
            $serverId = (int) $requestedServerId;
            $migration->server_id = $serverId;
            $migration->save();
        }

        if (!$serverId) {
            $serverId = (int) Server::orderBy('id', 'desc')->value('id');
            $migration->server_id = $serverId;
            $migration->save();
        }

        $server = Server::where('id', $serverId)->first();
        if (!$server) {
            $this->error('No server found for id=' . $serverId);
            return 1;
        }

        $latestIds = UserSubscription::select(DB::raw('MAX(id)'))
            ->groupBy('user_id', 'subscription_id');

        $query = UserSubscription::whereIn('id', $latestIds)
            ->orderBy('id', 'asc');

        if ($migration->last_processed_id > 0) {
            $query->where('id', '>', $migration->last_processed_id);
        }

        $subs = $query->limit($batchSize)->get();
        if ($subs->count() === 0) {
            $migration->update([
                'status' => 'completed',
                'finished_at' => Carbon::now(),
            ]);
            $this->info('Migration completed.');
            return 0;
        }

        foreach ($subs as $sub) {
            $migration->last_processed_id = $sub->id;
            try {
                $email = $this->extractEmail($sub);
                if (!$email) {
                    throw new \Exception('Email not found for user_subscription id=' . $sub->id);
                }

                if (!$this->subscriptionMatchesServer($sub, $email, (int) $serverId)) {
                    $migration->save();
                    continue;
                }

                if ($dryRun) {
                    $migration->processed_count++;
                    $migration->save();
                    continue;
                }

                $user = User::find($sub->user_id);
                if (!$user) {
                    throw new \Exception('User not found for user_subscription id=' . $sub->id);
                }

                $builder = new SubscriptionPackageBuilder($server, $user);
                $datePart = $this->extractDatePartFromFilePath($sub->file_path);
                $package = $this->buildWithRetry($builder, $email, $datePart, 2);

                if (!empty($sub->file_path) && $sub->file_path !== $package['file_path']) {
                    $this->deleteOldArchive($sub->file_path);
                }

                $sub->update([
                    'file_path' => $package['file_path'],
                ]);

                $migration->processed_count++;
            } catch (\Throwable $e) {
                $migration->error_count++;
                Log::error('Migration error: ' . $e->getMessage(), [
                    'user_subscription_id' => $sub->id,
                ]);
                \App\Models\SubscriptionMigrationItem::updateOrCreate([
                    'subscription_migration_id' => $migration->id,
                    'user_subscription_id' => $sub->id,
                ], [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $migration->save();
            }
        }

        $this->info('Processed batch of ' . $subs->count());
        return 0;
    }

    private function extractEmail(UserSubscription $sub): ?string
    {
        if (!empty($sub->file_path)) {
            $name = pathinfo(basename($sub->file_path), PATHINFO_FILENAME);
            $parts = explode('_', $name);
            if (count($parts) >= 2) {
                return $this->normalizeEmail($parts[1]);
            }
        }

        if (!empty($sub->connection_config)) {
            $pos = strrpos($sub->connection_config, '#');
            if ($pos !== false) {
                return $this->normalizeEmail(substr($sub->connection_config, $pos + 1));
            }
        }

        return null;
    }

    private function normalizeEmail(string $email): string
    {
        if (str_starts_with($email, 'vless-')) {
            return substr($email, 6);
        }

        return $email;
    }

    private function subscriptionMatchesServer(UserSubscription $sub, string $email, int $serverId): bool
    {
        if (!empty($sub->file_path)) {
            $name = pathinfo(basename($sub->file_path), PATHINFO_FILENAME);
            $parts = explode('_', $name);
            if (isset($parts[2]) && (int) $parts[2] === $serverId) {
                return true;
            }
        }

        if (!ctype_digit($email)) {
            return false;
        }

        return str_ends_with($email, (string) $serverId);
    }

    private function deleteOldArchive(?string $path): void
    {
        if (!$path) {
            return;
        }

        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $relative = ltrim($path, '/');
            if ($disk->exists($relative)) {
                $disk->delete($relative);
            }

            $dir = trim(dirname($relative), '/');
            $base = pathinfo($relative, PATHINFO_FILENAME);
            $dirBase = basename($dir);

            // Delete only archive folder: files/<archive-name>/
            if ($dir && $dir !== '.' && $dir !== 'files' && $dirBase === $base && $disk->exists($dir)) {
                $disk->deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            // best-effort cleanup
        }
    }

    private function extractDatePartFromFilePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $name = pathinfo(basename($path), PATHINFO_FILENAME);
        $parts = explode('_', $name);

        if (count($parts) < 8) {
            return null;
        }

        $tail = array_slice($parts, -5);
        foreach ($tail as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
        }

        return implode('_', $tail);
    }

    private function buildWithRetry(SubscriptionPackageBuilder $builder, string $email, ?string $datePart, int $retries): array
    {
        $attempts = 0;
        $last = null;
        $max = max(0, $retries);

        while ($attempts <= $max) {
            try {
                return $builder->buildForEmail($email, $datePart);
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempts >= $max) {
                    break;
                }
                $attempts++;
                usleep(500000);
            }
        }

        throw $last ?: new \Exception('Build failed');
    }
}
