<?php

namespace App\Models\components;

use App\Models\Server;
use App\Models\User;
use App\Services\VpnAgent\Node1Provisioner;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SubscriptionPackageBuilder
{
    private const INSTALLER_ROOT = 'storage/app/public/files';
    private const INSTALLER_TARGET = 'files/wireguard-installer.exe';
    private const HAPP_INSTALLER_TARGET = 'files/setup-Happ.x64.exe';

    private Server $server;
    private User $user;

    public function __construct(Server $server, User $user)
    {
        $this->server = $server;
        $this->user = $user;
    }

    /**
     * @return array{file_path: string, email: string, vless_url: string, wireguard_config: string}
     * @throws Exception
     */
    public function build(): array
    {
        // Tests should not depend on external server services.
        if (app()->environment('testing')) {
            return [
                'file_path' => $this->fakeFilePath((string) ($this->user->email ?? 'test@example.invalid')),
                'email' => (string) ($this->user->email ?? 'test@example.invalid'),
                'vless_url' => '',
                'wireguard_config' => '',
            ];
        }

        $server = $this->server;

        if (!$server->usesNode1Api() && empty($server->url1)) {
            throw new Exception('Server node1 URL is not configured');
        }

        $email = $this->generatePeerName();

        return $this->buildForEmail($email);
    }

    /**
     * @return array{file_path: string, email: string, vless_url: string, wireguard_config: string}
     * @throws Exception
     */
    public function buildForEmail(string $email, ?string $datePart = null): array
    {
        // Tests should not depend on external server services.
        if (app()->environment('testing')) {
            return [
                'file_path' => $this->fakeFilePath($email),
                'email' => $email,
                'vless_url' => '',
                'wireguard_config' => '',
            ];
        }

        $server = $this->server;

        if (!$server->usesNode1Api() && empty($server->url1)) {
            throw new Exception('Server node1 URL is not configured');
        }

        $wireguardConfig = null;
        if ($server->usesNode1Api()) {
            $wireguardConfig = (new Node1Provisioner())->createOrGetConfig($server, $email);
            if (empty($wireguardConfig)) {
                throw new Exception('Node1 API returned empty WireGuard config');
            }
        } else {
            $inboundManager = new InboundManagerVless($server->url1);
            try {
                $wireguard = $inboundManager->findOrCreateWireguardInbound($email, $server->username1, $server->password1);
                $wireguardConfig = $wireguard['connection_data']['config'] ?? null;
            } catch (Exception $e) {
                Log::warning('WireGuard create error: ' . $e->getMessage());
            }

            if (!$wireguardConfig) {
                $wireguardConfig = $inboundManager->getWireguardConfigByRemark($email, $server->username1, $server->password1);
            }
        }

        if (!empty($wireguardConfig)) {
            $wireguardConfig = WireguardQrCode::normalizeConfig($wireguardConfig);
        }

        $amneziaWgConfig = $wireguardConfig ?: '';
        $wireguardQrDataUri = $wireguardConfig ? WireguardQrCode::makeDataUri($wireguardConfig) : null;
        $awgQrDataUri = $amneziaWgConfig !== '' ? WireguardQrCode::makePlainDataUri($amneziaWgConfig) : null;
        $manualHtml = $this->buildManualHtml($wireguardQrDataUri, $awgQrDataUri, $wireguardConfig);

        $zipRelativePath = $this->buildArchive($email, $manualHtml, $wireguardConfig, $amneziaWgConfig, $datePart);

        return [
            'file_path' => $zipRelativePath,
            'email' => $email,
            'vless_url' => '',
            'wireguard_config' => $wireguardConfig,
        ];
    }

    private function buildManualHtml(?string $wireguardQrDataUri = null, ?string $awgQrDataUri = null, ?string $wireguardConfig = null): string
    {
        $body = view('subscription.manual_zip', [
            'wireguardQrDataUri' => $wireguardQrDataUri,
            'awgQrDataUri' => $awgQrDataUri,
            'wireguardConfig' => $wireguardConfig,
        ])->render();

        return "<!doctype html><html lang=\"ru\"><head><meta charset=\"utf-8\"><title>Инструкция</title></head><body>{$body}</body></html>";
    }

    private function buildArchive(string $email, string $manualHtml, ?string $wireguardConfig, ?string $amneziaWgConfig = null, ?string $datePart = null): string
    {
        $datePart = $datePart ?: Carbon::now()->format('d_m_Y_H_i');
        $fileName = $this->user->id . '_' . $email . '_' . $this->server->id . '_' . $datePart . '.zip';
        $folderBase = pathinfo($fileName, PATHINFO_FILENAME);
        $zipRelativeDir = 'files/' . $folderBase;
        $zipRelativePath = $zipRelativeDir . '/' . $fileName;
        Storage::disk('public')->makeDirectory($zipRelativeDir);
        $zipAbsolutePath = Storage::disk('public')->path($zipRelativePath);

        $folderName = $email . '_' . $this->server->id;

        $zip = new ZipArchive();
        if ($zip->open($zipAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Cannot create zip: ' . $zipAbsolutePath);
        }

        $zip->addFromString($folderName . '/manual.html', $manualHtml);
        if (!empty($wireguardConfig)) {
            $zip->addFromString($folderName . '/peer-1.conf', $wireguardConfig);
            $wireguardQrPng = WireguardQrCode::makePng($wireguardConfig);
            if ($wireguardQrPng) {
                $zip->addFromString($folderName . '/wireguard-qr.png', $wireguardQrPng);
            }
        }
        if (!empty($amneziaWgConfig)) {
            $zip->addFromString($folderName . '/peer-1-amneziawg.conf', $amneziaWgConfig);
            $awgQrPng = WireguardQrCode::makePlainPng($amneziaWgConfig);
            if ($awgQrPng) {
                $zip->addFromString($folderName . '/amneziawg-qr.png', $awgQrPng);
            }
        }

        $zip->close();

        return $zipRelativePath;
    }

    private function resolveInstallerPath(): ?string
    {
        $target = Storage::disk('public')->path(self::INSTALLER_TARGET);
        if (File::exists($target)) {
            return $target;
        }

        $root = base_path(self::INSTALLER_ROOT);
        if (!File::exists($root)) {
            return null;
        }

        $source = null;
        $files = File::allFiles($root);
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (preg_match('/^wireguard-installer\\s*\\.exe$/i', $name)) {
                $source = $file->getPathname();
                break;
            }
        }

        if (!$source) {
            return null;
        }
        $targetDir = dirname($target);
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        File::copy($source, $target);
        Log::info('WireGuard installer copied to: ' . $target);

        return $target;
    }

    private function resolveHappInstallerPath(): ?string
    {
        $target = Storage::disk('public')->path(self::HAPP_INSTALLER_TARGET);
        if (File::exists($target)) {
            return $target;
        }

        $root = base_path(self::INSTALLER_ROOT);
        if (!File::exists($root)) {
            return null;
        }

        $source = null;
        $files = File::allFiles($root);
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (preg_match('/^setup-Happ\\.x64\\.exe$/i', $name)) {
                $source = $file->getPathname();
                break;
            }
        }

        if (!$source) {
            return null;
        }

        $targetDir = dirname($target);
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        File::copy($source, $target);
        Log::info('Happ installer copied to: ' . $target);

        return $target;
    }

    private function normalizeVlessUrl(string $url, string $email): string
    {
        $normalized = preg_replace('/#.+$/', '', $url);
        return $normalized . '#' . $email;
    }

    private function fakeFilePath(string $email): string
    {
        $safeEmail = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $email) ?: 'test';

        return sprintf(
            'files/%d_%s_%d_%s.zip',
            (int) ($this->user->id ?? 0),
            $safeEmail,
            (int) ($this->server->id ?? 0),
            Carbon::now()->format('d_m_Y_H_i')
        );
    }

    private function generatePeerName(): string
    {
        return sprintf(
            'vpn-%d-%d-%s',
            (int) ($this->server->id ?? 0),
            (int) ($this->user->id ?? 0),
            Carbon::now()->format('ymdHisv')
        );
    }
}
