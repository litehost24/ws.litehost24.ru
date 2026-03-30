<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\components\InboundManagerVless;
use App\Models\components\SubscriptionPackageBuilder;
use App\Models\components\UserManagerVless;
use App\Services\Vless\UserStatusManager;
use App\Support\VpnPeerName;
use Exception;

class SubscriptionVpnAccessModeSwitcher
{
    /**
     * @throws Exception
     */
    public function switch(UserSubscription $subscription, string $targetMode): UserSubscription
    {
        $targetMode = Server::normalizeVpnAccessMode($targetMode);
        $currentMode = $subscription->resolveVpnAccessMode();
        if ($currentMode !== null && $currentMode === $targetMode) {
            return $subscription;
        }

        $targetServer = Server::resolvePurchaseServer($targetMode);
        if (!$targetServer) {
            throw new Exception('Не настроен сервер для выбранного типа подключения.');
        }

        $subscription->loadMissing('user', 'subscription');

        $peerName = VpnPeerName::fromSubscription($subscription);
        if (!is_string($peerName) || trim($peerName) === '') {
            throw new Exception('Не удалось определить имя peer для перепривязки подписки.');
        }

        $currentServer = $subscription->resolveServer();

        $package = (new SubscriptionPackageBuilder($targetServer, $subscription->user))
            ->buildForEmail($peerName);

        $this->enableTarget($targetServer, $peerName);

        try {
            $this->disableSource($currentServer, $targetServer, $peerName);
        } catch (\Throwable $e) {
            $this->rollbackTarget($targetServer, $peerName);
            throw new Exception('Не удалось отключить старый сервер: ' . $e->getMessage(), 0, $e);
        }

        $subscription->update([
            'file_path' => $package['file_path'],
            'connection_config' => $package['vless_url'],
            'server_id' => (int) $targetServer->id,
            'vpn_access_mode' => $targetMode,
        ]);

        return $subscription->fresh(['subscription', 'user']) ?? $subscription;
    }

    /**
     * @throws Exception
     */
    private function enableTarget(Server $targetServer, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if ($targetServer->usesNode1Api()) {
            (new Node1Provisioner())->enableByName($targetServer, $peerName);
        }

        (new UserStatusManager())->enable($targetServer, $peerName);
    }

    /**
     * @throws Exception
     */
    private function disableSource(?Server $currentServer, Server $targetServer, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (!$currentServer || (int) $currentServer->id === (int) $targetServer->id) {
            return;
        }

        if ($currentServer->usesNode1Api()) {
            try {
                (new Node1Provisioner())->disableByName($currentServer, $peerName);
            } catch (\Throwable $e) {
                $normalized = mb_strtolower($e->getMessage());
                if (!str_contains($normalized, 'not found') && !str_contains($normalized, 'missing')) {
                    throw $e;
                }
            }
        } elseif (trim((string) $currentServer->url1) !== '') {
            $manager = new InboundManagerVless((string) $currentServer->url1);
            $result = $manager->disableInbound($peerName, (string) $currentServer->username1, (string) $currentServer->password1);
            if (!$this->isSuccess($result)) {
                throw new Exception('Не удалось отключить старый AWG peer.');
            }
        }

        if ($this->sharesSameVlessNode($currentServer, $targetServer)) {
            return;
        }

        if (
            trim((string) $currentServer->url2) === ''
            || trim((string) $currentServer->username2) === ''
            || trim((string) $currentServer->password2) === ''
        ) {
            return;
        }

        $userManager = new UserManagerVless((string) $currentServer->url2);
        $result = $userManager->disableUser($peerName, (string) $currentServer->username2, (string) $currentServer->password2);
        if (!$this->isSuccess($result)) {
            throw new Exception('Не удалось отключить старого VLESS пользователя.');
        }
    }

    private function rollbackTarget(Server $targetServer, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        try {
            if ($targetServer->usesNode1Api()) {
                (new Node1Provisioner())->disableByName($targetServer, $peerName);
            }
        } catch (\Throwable) {
        }
    }

    private function sharesSameVlessNode(Server $source, Server $target): bool
    {
        return trim((string) $source->url2) === trim((string) $target->url2)
            && trim((string) $source->username2) === trim((string) $target->username2);
    }

    private function isSuccess($result): bool
    {
        if (is_array($result) && array_key_exists('success', $result)) {
            return (bool) $result['success'];
        }

        if (is_bool($result)) {
            return $result;
        }

        return $result !== null;
    }
}
