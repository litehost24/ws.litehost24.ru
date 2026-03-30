<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\components\WireguardQrCode;
use App\Support\SubscriptionBundleMeta;
use App\Support\VpnPeerName;
use Exception;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class SubscriptionWireguardConfigResolver
{
    public function resolve(UserSubscription $subscription): string
    {
        $liveConfig = $this->resolveFromNode1Api($subscription);
        if ($liveConfig !== '') {
            return $liveConfig;
        }

        return $this->resolveFromArchivePath((string) ($subscription->file_path ?? ''));
    }

    private function resolveFromNode1Api(UserSubscription $subscription): string
    {
        $meta = SubscriptionBundleMeta::fromFilePath((string) ($subscription->file_path ?? ''));
        if ($meta === null) {
            return '';
        }

        $serverId = $meta->serverId();
        $server = Server::query()->find($serverId);
        if (!$server || !$server->usesNode1Api()) {
            return '';
        }

        $peerName = VpnPeerName::fromSubscription($subscription, $serverId);
        if ($peerName === null || $peerName === '') {
            return '';
        }

        try {
            $config = (new VpnAgentClient($server, 12))->exportNameIfExists($peerName);
        } catch (Exception $e) {
            Log::warning('Node1 export-name failed for subscription config resolve', [
                'server_id' => $serverId,
                'peer_name' => $peerName,
                'user_subscription_id' => (int) ($subscription->id ?? 0),
                'error' => $e->getMessage(),
            ]);

            return '';
        }

        $normalized = WireguardQrCode::normalizeConfig((string) $config);
        if ($normalized === '' || !str_contains($normalized, '[Interface]') || !str_contains($normalized, '[Peer]')) {
            return '';
        }

        return $normalized;
    }

    private function resolveFromArchivePath(string $filePath): string
    {
        $rel = trim($filePath);
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }

        $zipPath = storage_path('app/public/' . ltrim($rel, '/'));
        if (!is_file($zipPath)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '';
        }

        $content = '';
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (!$entryName || basename($entryName) !== 'peer-1.conf') {
                    continue;
                }

                $fileContent = $zip->getFromIndex($i);
                if (is_string($fileContent) && $fileContent !== '') {
                    $content = $fileContent;
                }
                break;
            }
        } finally {
            $zip->close();
        }

        return WireguardQrCode::normalizeConfig($content);
    }
}
