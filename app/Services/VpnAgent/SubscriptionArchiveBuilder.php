<?php

namespace App\Services\VpnAgent;

use App\Models\UserSubscription;
use App\Models\components\WireguardQrCode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use RuntimeException;
use ZipArchive;

class SubscriptionArchiveBuilder
{
    public function __construct(
        private readonly SubscriptionWireguardConfigResolver $configResolver,
    ) {
    }

    public function buildTemporaryArchive(UserSubscription $subscription, ?string $downloadName = null): ?string
    {
        $wireguardConfig = $this->configResolver->resolve($subscription);
        if ($wireguardConfig === '') {
            return null;
        }
        $amneziaWgConfig = $wireguardConfig;

        $downloadName = $this->normalizeDownloadName($subscription, $downloadName);
        $folderName = $this->deriveFolderName($subscription);
        $manualHtml = $this->buildManualHtml(
            $wireguardConfig,
            $amneziaWgConfig,
        );

        $tmpDir = storage_path('app/tmp/subscription-downloads');
        File::ensureDirectoryExists($tmpDir);

        $tmpPath = tempnam($tmpDir, 'subzip_');
        if ($tmpPath === false) {
            throw new RuntimeException('Unable to allocate temporary archive path');
        }

        $zipPath = $tmpPath . '.zip';
        if (!@rename($tmpPath, $zipPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Unable to prepare temporary archive path');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Unable to create temporary archive');
        }

        try {
            $zip->addFromString($folderName . '/manual.html', $manualHtml);
            $zip->addFromString($folderName . '/peer-1.conf', $wireguardConfig);
            if ($amneziaWgConfig !== '') {
                $zip->addFromString($folderName . '/peer-1-amneziawg.conf', $amneziaWgConfig);
            }

            $wireguardQrPng = WireguardQrCode::makePng($wireguardConfig);
            if ($wireguardQrPng !== null) {
                $zip->addFromString($folderName . '/wireguard-qr.png', $wireguardQrPng);
            }

            $amneziaWgQrPng = $amneziaWgConfig !== '' ? WireguardQrCode::makePlainPng($amneziaWgConfig) : null;
            if ($amneziaWgQrPng !== null) {
                $zip->addFromString($folderName . '/amneziawg-qr.png', $amneziaWgQrPng);
            }
        } finally {
            $zip->close();
        }

        return $zipPath;
    }

    private function buildManualHtml(string $wireguardConfig, string $amneziaWgConfig): string
    {
        $body = View::make('subscription.manual_zip', [
            'wireguardQrDataUri' => WireguardQrCode::makeDataUri($wireguardConfig),
            'awgQrDataUri' => $amneziaWgConfig !== '' ? WireguardQrCode::makePlainDataUri($amneziaWgConfig) : null,
            'wireguardConfig' => $wireguardConfig,
        ])->render();

        return "<!doctype html><html lang=\"ru\"><head><meta charset=\"utf-8\"><title>Инструкция</title></head><body>{$body}</body></html>";
    }

    private function normalizeDownloadName(UserSubscription $subscription, ?string $downloadName): string
    {
        $name = trim((string) $downloadName);
        if ($name !== '') {
            return $name;
        }

        $filePath = trim((string) ($subscription->file_path ?? ''));
        if ($filePath !== '') {
            $basename = basename($filePath);
            if ($basename !== '') {
                return $basename;
            }
        }

        return 'subscription_' . (int) ($subscription->id ?? 0) . '.zip';
    }

    private function deriveFolderName(UserSubscription $subscription): string
    {
        $filePath = trim((string) ($subscription->file_path ?? ''));
        $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
        if ($base !== '') {
            $parts = explode('_', $base);
            if (count($parts) >= 3 && $parts[1] !== '' && $parts[2] !== '') {
                return $parts[1] . '_' . $parts[2];
            }
        }

        return 'subscription_' . (int) ($subscription->id ?? 0);
    }
}
