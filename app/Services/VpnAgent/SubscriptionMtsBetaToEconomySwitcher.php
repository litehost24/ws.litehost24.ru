<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\components\SubscriptionPackageBuilder;
use App\Services\VpnPlanCatalog;
use App\Support\VpnPeerName;
use Exception;
use Illuminate\Support\Carbon;

class SubscriptionMtsBetaToEconomySwitcher
{
    public const SOURCE_PLAN_CODE = 'restricted_mts_beta';
    public const TARGET_PLAN_CODE = 'restricted_economy';

    public function __construct(
        private readonly SubscriptionPeerOperator $peerOperator,
    ) {
    }

    /**
     * @throws Exception
     */
    public function switch(UserSubscription $subscription): UserSubscription
    {
        if (trim((string) ($subscription->vpn_plan_code ?? '')) !== self::SOURCE_PLAN_CODE) {
            throw new Exception('Перевод на Эконом доступен только для тарифа Для сети МТС (бета).');
        }

        if ($subscription->hasPendingVpnAccessModeSwitch()) {
            throw new Exception('Сначала дождитесь завершения текущего переключения подключения.');
        }

        $catalog = app(VpnPlanCatalog::class);
        $targetPlan = $catalog->snapshot(self::TARGET_PLAN_CODE);
        if ($targetPlan === null) {
            throw new Exception('Не удалось определить параметры тарифа Эконом.');
        }

        $targetMode = (string) ($targetPlan['vpn_access_mode'] ?? Server::VPN_ACCESS_WHITE_IP);
        $targetServer = Server::resolvePurchaseServer($targetMode, self::TARGET_PLAN_CODE);
        if (!$targetServer) {
            throw new Exception('Не настроен сервер для тарифа Эконом.');
        }

        $subscription->loadMissing('user', 'subscription');

        $currentServer = $subscription->resolveServer();
        $oldServerId = $subscription->resolveServerId();
        $oldPeerName = VpnPeerName::fromSubscription($subscription, $oldServerId);
        if (!is_string($oldPeerName) || trim($oldPeerName) === '') {
            throw new Exception('Не удалось определить текущий peer подписки.');
        }

        $newPeerName = $this->generateTargetPeerName($subscription, (int) $targetServer->id, $oldPeerName);
        $package = (new SubscriptionPackageBuilder($targetServer, $subscription->user))
            ->buildForEmail($newPeerName);

        $this->enableTarget($targetServer, $newPeerName, (int) $subscription->user_id);

        try {
            $this->disableSource($currentServer, $oldPeerName, (int) $subscription->user_id);
        } catch (\Throwable $e) {
            $this->rollbackTarget($targetServer, $newPeerName);
            throw new Exception('Не удалось отключить старый MTS-конфиг: ' . $e->getMessage(), 0, $e);
        }

        try {
            $subscription->update([
                'file_path' => (string) ($package['file_path'] ?? ''),
                'connection_config' => null,
                'server_id' => (int) $targetServer->id,
                'vpn_access_mode' => $targetMode,
                'vpn_plan_code' => (string) ($targetPlan['vpn_plan_code'] ?? self::TARGET_PLAN_CODE),
                'vpn_plan_name' => (string) ($targetPlan['vpn_plan_name'] ?? 'Эконом'),
                'vpn_traffic_limit_bytes' => $targetPlan['vpn_traffic_limit_bytes'] ?? null,
                'pending_vpn_access_mode_source_server_id' => null,
                'pending_vpn_access_mode_source_peer_name' => null,
                'pending_vpn_access_mode_disconnect_at' => null,
                'pending_vpn_access_mode_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->rollbackTarget($targetServer, $newPeerName);
            $this->restoreSource($currentServer, $oldPeerName, (int) $subscription->user_id);
            throw new Exception('Не удалось сохранить переход на Эконом: ' . $e->getMessage(), 0, $e);
        }

        return $subscription->fresh(['subscription', 'user']) ?? $subscription;
    }

    /**
     * @throws Exception
     */
    private function enableTarget(Server $targetServer, string $peerName, int $userId): void
    {
        if ($targetServer->usesNode1Api()) {
            $this->peerOperator->enableNodePeer($targetServer, $peerName);
        }

        $this->peerOperator->syncServerState($targetServer, $peerName, 'enabled', $userId);
    }

    /**
     * @throws Exception
     */
    private function disableSource(?Server $currentServer, string $peerName, int $userId): void
    {
        if (!$currentServer) {
            return;
        }

        if ($currentServer->usesNode1Api()) {
            $this->peerOperator->disableNodePeer($currentServer, $peerName, true);
        } elseif (trim((string) $currentServer->url1) !== '') {
            $this->peerOperator->disableInboundPeer($currentServer, $peerName);
        }

        $this->peerOperator->syncServerState($currentServer, $peerName, 'disabled', $userId);
    }

    private function rollbackTarget(Server $targetServer, string $peerName): void
    {
        try {
            if ($targetServer->usesNode1Api()) {
                $this->peerOperator->disableNodePeer($targetServer, $peerName, true);
            } elseif (trim((string) $targetServer->url1) !== '') {
                $this->peerOperator->disableInboundPeer($targetServer, $peerName);
            }

            $this->peerOperator->syncServerState($targetServer, $peerName, 'disabled');
        } catch (\Throwable) {
        }
    }

    private function restoreSource(?Server $currentServer, string $peerName, int $userId): void
    {
        if (!$currentServer) {
            return;
        }

        try {
            if ($currentServer->usesNode1Api()) {
                $this->peerOperator->enableNodePeer($currentServer, $peerName);
            } elseif (trim((string) $currentServer->url1) !== '') {
                $this->peerOperator->enableInboundPeer($currentServer, $peerName);
            }

            $this->peerOperator->syncServerState($currentServer, $peerName, 'enabled', $userId);
        } catch (\Throwable) {
        }
    }

    private function generateTargetPeerName(UserSubscription $subscription, int $serverId, string $oldPeerName): string
    {
        $base = sprintf(
            'vpn-%d-%d-%s',
            $serverId,
            (int) $subscription->user_id,
            Carbon::now()->format('ymdHisv')
        );

        return $base === $oldPeerName
            ? $base . '-e'
            : $base;
    }
}
