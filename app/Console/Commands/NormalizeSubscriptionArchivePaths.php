<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use App\Services\VpnAgent\SubscriptionArchiveBuilder;
use App\Support\VpnPeerName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class NormalizeSubscriptionArchivePaths extends Command
{
    protected $signature = 'subscriptions:normalize-archive-paths
        {--dry-run : Only show what would be changed}
        {--user-id= : Limit normalization to one user}';

    protected $description = 'Normalize subscription archive file_path values to files/<base>/<base>.zip and ensure the archive exists there.';

    public function handle(SubscriptionArchiveBuilder $archiveBuilder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;

        $stats = [
            'planned' => 0,
            'updated' => 0,
            'rebuilt' => 0,
            'copied' => 0,
            'refreshed' => 0,
            'skipped_canonical' => 0,
            'skipped_invalid' => 0,
            'skipped_conflict' => 0,
            'failed' => 0,
        ];
        $errors = [];

        $query = UserSubscription::query()
            ->when($userId !== null, function ($builder) use ($userId) {
                $builder->where('user_id', $userId);
            })
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->orderBy('id', 'asc');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        foreach ($query->cursor() as $subscription) {
            $currentPath = $this->normalizeRelativePath((string) ($subscription->file_path ?? ''));
            $canonicalPath = $this->canonicalRelativePath($currentPath);

            if ($currentPath === '' || $canonicalPath === null) {
                $stats['skipped_invalid']++;
                continue;
            }

            if ($currentPath === $canonicalPath) {
                $stats['skipped_canonical']++;
                continue;
            }

            $stats['planned']++;

            $currentExists = $disk->exists($currentPath);
            $canonicalExists = $disk->exists($canonicalPath);
            $currentConfig = $currentExists ? $this->extractWireguardConfig($disk->path($currentPath)) : '';
            $canonicalConfig = $canonicalExists ? $this->extractWireguardConfig($disk->path($canonicalPath)) : '';

            if (!$currentExists && !$canonicalExists && !$this->canRebuildArchive($subscription)) {
                $stats['skipped_invalid']++;
                $errors[] = [
                    'user_subscription_id' => (int) $subscription->id,
                    'user_id' => (int) $subscription->user_id,
                    'current_path' => $currentPath,
                    'canonical_path' => $canonicalPath,
                    'reason' => 'archive-missing-and-metadata-invalid',
                ];
                continue;
            }

            if (
                $currentExists
                && $canonicalExists
                && $currentConfig !== ''
                && $canonicalConfig !== ''
                && $currentConfig !== $canonicalConfig
            ) {
                $stats['skipped_conflict']++;
                $errors[] = [
                    'user_subscription_id' => (int) $subscription->id,
                    'user_id' => (int) $subscription->user_id,
                    'current_path' => $currentPath,
                    'canonical_path' => $canonicalPath,
                    'reason' => 'archive-config-mismatch',
                ];
                continue;
            }

            if ($dryRun) {
                continue;
            }

            try {
                $canonicalAbsolutePath = $disk->path($canonicalPath);
                File::ensureDirectoryExists(dirname($canonicalAbsolutePath));

                if ($currentExists) {
                    if (!$canonicalExists || ($canonicalConfig === '' && $currentConfig !== '')) {
                        $this->writeFile($canonicalAbsolutePath, $disk->path($currentPath));
                        if ($canonicalExists) {
                            $stats['refreshed']++;
                        } else {
                            $stats['copied']++;
                        }
                    }
                } elseif (!$canonicalExists) {
                    $tmpArchivePath = $archiveBuilder->buildTemporaryArchive($subscription, basename($canonicalPath));
                    if (!is_string($tmpArchivePath) || !is_file($tmpArchivePath)) {
                        throw new \RuntimeException('temporary archive build failed');
                    }

                    $this->writeFile($canonicalAbsolutePath, $tmpArchivePath);
                    @unlink($tmpArchivePath);
                    $stats['rebuilt']++;
                }

                if (!$disk->exists($canonicalPath)) {
                    throw new \RuntimeException('canonical archive path was not created');
                }

                $subscription->forceFill([
                    'file_path' => $canonicalPath,
                ])->save();

                $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $errors[] = [
                    'user_subscription_id' => (int) $subscription->id,
                    'user_id' => (int) $subscription->user_id,
                    'current_path' => $currentPath,
                    'canonical_path' => $canonicalPath,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $this->line(json_encode([
            'dry_run' => $dryRun,
            'user_id' => $userId,
            'stats' => $stats,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function canonicalRelativePath(string $path): ?string
    {
        $basename = basename($path);
        if ($basename === '' || strtolower(pathinfo($basename, PATHINFO_EXTENSION)) !== 'zip') {
            return null;
        }

        $base = pathinfo($basename, PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        return 'files/' . $base . '/' . $basename;
    }

    private function extractWireguardConfig(string $zipPath): string
    {
        if (!is_file($zipPath)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '';
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (!$entryName || basename($entryName) !== 'peer-1.conf') {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                return is_string($content) ? trim($content) : '';
            }
        } finally {
            $zip->close();
        }

        return '';
    }

    private function writeFile(string $targetPath, string $sourcePath): void
    {
        $read = fopen($sourcePath, 'rb');
        if ($read === false) {
            throw new \RuntimeException('failed to open source archive');
        }

        $write = fopen($targetPath, 'wb');
        if ($write === false) {
            fclose($read);
            throw new \RuntimeException('failed to open target archive');
        }

        try {
            stream_copy_to_stream($read, $write);
        } finally {
            fclose($read);
            fclose($write);
        }
    }

    private function canRebuildArchive(UserSubscription $subscription): bool
    {
        $server = $subscription->resolveServer();
        if (!$server) {
            return false;
        }

        $peerName = VpnPeerName::fromSubscription($subscription, (int) $server->id);

        return is_string($peerName) && trim($peerName) !== '';
    }
}
