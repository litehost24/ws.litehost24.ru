<?php

namespace App\Support;

use App\Models\VpnPeerEndpointEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AmneziaSharingDetector
{
    public const WINDOW_MINUTES = 15;
    public const WARNING_CHANGES = 5;
    public const WARNING_ENDPOINTS = 2;
    public const CRITICAL_CHANGES = 10;
    public const CRITICAL_ENDPOINTS = 3;

    /**
     * @param Collection<int, mixed> $subscriptions
     * @return array<string, array{
     *     level: 'warning'|'critical',
     *     changes: int,
     *     distinct_endpoints: int,
     *     last_endpoint: string|null,
     *     tooltip: string
     * }>
     */
    public static function forSubscriptions(Collection $subscriptions, ?int $serverId): array
    {
        if ($serverId === null || $serverId <= 0 || $subscriptions->isEmpty()) {
            return [];
        }

        if (!Schema::hasTable('vpn_peer_endpoint_events')) {
            return [];
        }

        $peerMap = $subscriptions
            ->filter(function ($subscription) {
                return !empty($subscription->peer_name) && !empty($subscription->user_id);
            })
            ->mapWithKeys(function ($subscription) {
                $key = (int) $subscription->user_id . ':' . (string) $subscription->peer_name;
                return [$key => [
                    'user_id' => (int) $subscription->user_id,
                    'peer_name' => (string) $subscription->peer_name,
                ]];
            });

        if ($peerMap->isEmpty()) {
            return [];
        }

        $peerNames = $peerMap->pluck('peer_name')->unique()->values();
        $userIds = $peerMap->pluck('user_id')->unique()->values();

        $cutoff = Carbon::now()->subMinutes(self::WINDOW_MINUTES);
        $events = VpnPeerEndpointEvent::query()
            ->where('server_id', $serverId)
            ->whereIn('user_id', $userIds->all())
            ->whereIn('peer_name', $peerNames->all())
            ->where('seen_at', '>=', $cutoff)
            ->orderBy('seen_at')
            ->get(['user_id', 'peer_name', 'endpoint', 'seen_at']);

        $result = [];

        foreach ($events->groupBy(function ($row) {
            return (int) $row->user_id . ':' . (string) $row->peer_name;
        }) as $peerKey => $rows) {
            if (!$peerMap->has((string) $peerKey)) {
                continue;
            }

            $changes = $rows->count();
            $distinctEndpoints = $rows->pluck('endpoint')->filter()->unique()->values();
            $distinctCount = $distinctEndpoints->count();
            $level = self::resolveLevel($changes, $distinctCount);

            if ($level === null) {
                continue;
            }

            $lastEndpoint = (string) ($rows->last()->endpoint ?? '');
            $tooltip = ($level === 'critical' ? 'Высокий риск шаринга' : 'Подозрение на шаринг')
                . ': смен endpoint за ' . self::WINDOW_MINUTES . ' мин: ' . $changes
                . ', endpoint: ' . $distinctCount;

            if ($lastEndpoint !== '') {
                $tooltip .= ', последний: ' . $lastEndpoint;
            }

            $result[(string) $peerKey] = [
                'level' => $level,
                'changes' => $changes,
                'distinct_endpoints' => $distinctCount,
                'last_endpoint' => $lastEndpoint !== '' ? $lastEndpoint : null,
                'tooltip' => $tooltip,
            ];
        }

        return $result;
    }

    private static function resolveLevel(int $changes, int $distinctEndpoints): ?string
    {
        if ($changes >= self::CRITICAL_CHANGES || $distinctEndpoints >= self::CRITICAL_ENDPOINTS) {
            return 'critical';
        }

        if ($changes >= self::WARNING_CHANGES && $distinctEndpoints >= self::WARNING_ENDPOINTS) {
            return 'warning';
        }

        return null;
    }
}
