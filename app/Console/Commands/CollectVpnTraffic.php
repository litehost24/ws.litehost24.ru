<?php

namespace App\Console\Commands;

use App\Models\ServerAwgSummary;
use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\VpnPeerEndpointEvent;
use App\Models\VpnPeerServerState;
use App\Models\VpnPeerTrafficDaily;
use App\Models\VpnPeerTrafficSnapshot;
use App\Services\VpnAgent\VpnAgentClient;
use App\Services\Xui\XuiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CollectVpnTraffic extends Command
{
    private const HEAVY_PEER_THRESHOLD_MBPS = 15.0;
    private const TOP_PEERS_LIMIT = 5;

    protected $signature = 'vpn:traffic-collect {--server-id=} {--once : Print collection summary}';

    protected $description = 'Collect VPN peer traffic from node1 API and 3x-ui, aggregate daily deltas.';

    /** @var array<string, UserSubscription|null> */
    private array $subscriptionByPeerName = [];
    /** @var array<int, bool> */
    private array $subscriptionMapLoaded = [];

    public function handle(): int
    {
        $serverId = $this->option('server-id');
        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->when($serverId, fn ($q) => $q->where('id', (int) $serverId))
            ->orderBy('id')
            ->get();

        $processedPeers = 0;
        $processedServers = 0;

        foreach ($servers as $server) {
            $processedPeers += $this->collectNode1Traffic($server);
            $processedPeers += $this->collectVlessTraffic($server);
            $processedServers++;
        }

        if ($this->option('once')) {
            $this->info("Processed servers: {$processedServers}; peers: {$processedPeers}");
        }

        return self::SUCCESS;
    }

    private function collectNode1Traffic(Server $server): int
    {
        $processedPeers = 0;

        $client = new VpnAgentClient($server, 20);
        $serverStatusData = $this->fetchAndStoreServerStatuses($client, (int) $server->id);
        $serverStatusByName = $serverStatusData['map'];
        $statusesAvailable = (bool) $serverStatusData['available'];

        try {
            $response = $client->peersStats();
        } catch (\Throwable $e) {
            Log::error('VPN traffic collect peers-stats request failed: ' . $e->getMessage(), ['server_id' => $server->id]);
            return 0;
        }

        if (!(bool) ($response['ok'] ?? false)) {
            Log::warning('VPN traffic collect peers-stats returned not ok', [
                'server_id' => $server->id,
                'error' => (string) ($response['error'] ?? 'unknown_error'),
            ]);
            return 0;
        }

        $peers = $response['peers'] ?? [];
        if (!is_array($peers)) {
            Log::warning('VPN traffic collect peers-stats payload is invalid', ['server_id' => $server->id]);
            return 0;
        }

        $this->updateNode1Summary((int) $server->id, $peers, $serverStatusByName);

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            $name = trim((string) ($peer['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $status = $serverStatusByName[$name]['server_status'] ?? ($statusesAvailable ? 'missing' : 'unknown');
            $this->upsertServerState((int) $server->id, $name, $peer, $status);
            $this->processPeer((int) $server->id, $name, $peer, $status);
            $processedPeers++;
        }

        return $processedPeers;
    }

    /**
     * @param array<int, array<string, mixed>> $peers
     * @param array<string, array<string, mixed>> $serverStatusByName
     */
    private function updateNode1Summary(int $serverId, array $peers, array $serverStatusByName): void
    {
        $now = Carbon::now();
        $nowTs = $now->timestamp;
        $previousSnapshots = VpnPeerTrafficSnapshot::query()
            ->where('server_id', $serverId)
            ->get(['peer_name', 'user_id', 'ip', 'rx_bytes', 'tx_bytes', 'captured_at'])
            ->keyBy('peer_name');

        $summary = [
            'server_id' => $serverId,
            'collected_at' => $now,
            'window_sec' => 0,
            'peers_total' => 0,
            'peers_with_endpoint' => 0,
            'peers_active_5m' => 0,
            'peers_active_60s' => 0,
            'peers_transferring' => 0,
            'total_rx_mbps' => 0.0,
            'total_tx_mbps' => 0.0,
            'total_mbps' => 0.0,
            'avg_mbps_per_endpoint' => 0.0,
            'avg_mbps_per_active_5m' => 0.0,
            'heavy_peers_count' => 0,
            'top_peer_name' => null,
            'top_peer_user_id' => null,
            'top_peer_ip' => null,
            'top_peer_mbps' => null,
            'top_peer_share_percent' => null,
            'top_peers' => [],
        ];

        $topPeers = [];
        $windowSecMax = 0;
        $totalRateBps = 0.0;
        $totalRxRateBps = 0.0;
        $totalTxRateBps = 0.0;

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            $name = trim((string) ($peer['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $summary['peers_total']++;
            $status = $serverStatusByName[$name] ?? [];
            $endpoint = trim((string) ($status['endpoint'] ?? ''));
            if ($endpoint !== '') {
                $summary['peers_with_endpoint']++;
            }

            $latestHandshake = isset($status['latest_handshake_epoch']) && is_numeric($status['latest_handshake_epoch'])
                ? (int) $status['latest_handshake_epoch']
                : (isset($peer['latest_handshake_epoch']) && is_numeric($peer['latest_handshake_epoch'])
                    ? (int) $peer['latest_handshake_epoch']
                    : null);

            if ($latestHandshake && ($nowTs - $latestHandshake) <= 300) {
                $summary['peers_active_5m']++;
            }
            if ($latestHandshake && ($nowTs - $latestHandshake) <= 60) {
                $summary['peers_active_60s']++;
            }

            $previous = $previousSnapshots->get($name);
            $previousAt = $previous?->captured_at;
            $windowSec = $previousAt ? max(1, $now->diffInSeconds($previousAt)) : 0;
            $windowSecMax = max($windowSecMax, $windowSec);

            $currentRx = max(0, (int) ($peer['rx_bytes'] ?? 0));
            $currentTx = max(0, (int) ($peer['tx_bytes'] ?? 0));
            $previousRx = $previous ? max(0, (int) ($previous->rx_bytes ?? 0)) : 0;
            $previousTx = $previous ? max(0, (int) ($previous->tx_bytes ?? 0)) : 0;
            $deltaRx = $windowSec > 0 ? ($currentRx >= $previousRx ? $currentRx - $previousRx : $currentRx) : 0;
            $deltaTx = $windowSec > 0 ? ($currentTx >= $previousTx ? $currentTx - $previousTx : $currentTx) : 0;
            $rateRxBps = $windowSec > 0 ? ($deltaRx * 8) / $windowSec : 0.0;
            $rateTxBps = $windowSec > 0 ? ($deltaTx * 8) / $windowSec : 0.0;
            $rateTotalBps = $rateRxBps + $rateTxBps;

            if ($rateTotalBps > 0) {
                $summary['peers_transferring']++;
            }

            $totalRateBps += $rateTotalBps;
            $totalRxRateBps += $rateRxBps;
            $totalTxRateBps += $rateTxBps;

            if (($rateTotalBps / 1000 / 1000) >= self::HEAVY_PEER_THRESHOLD_MBPS) {
                $summary['heavy_peers_count']++;
            }

            if ($rateTotalBps <= 0) {
                continue;
            }

            $subscription = $this->resolveSubscription($serverId, $name);
            $ip = $this->stripCidr((string) (($peer['ip'] ?? null) ?: ($status['ip'] ?? null) ?: ($previous?->ip ?? '')));

            $topPeers[] = [
                'peer_name' => $name,
                'user_id' => $subscription ? (int) $subscription->user_id : ($previous?->user_id ? (int) $previous->user_id : null),
                'ip' => $ip !== '' ? $ip : null,
                'rate_bps' => $rateTotalBps,
                'rate_mbps' => round($rateTotalBps / 1000 / 1000, 2),
            ];
        }

        usort($topPeers, fn (array $left, array $right) => $right['rate_bps'] <=> $left['rate_bps']);

        $summary['window_sec'] = $windowSecMax;
        $summary['total_rx_mbps'] = round($totalRxRateBps / 1000 / 1000, 2);
        $summary['total_tx_mbps'] = round($totalTxRateBps / 1000 / 1000, 2);
        $summary['total_mbps'] = round($totalRateBps / 1000 / 1000, 2);
        $summary['avg_mbps_per_endpoint'] = $summary['peers_with_endpoint'] > 0
            ? round(($totalRateBps / $summary['peers_with_endpoint']) / 1000 / 1000, 2)
            : 0.0;
        $summary['avg_mbps_per_active_5m'] = $summary['peers_active_5m'] > 0
            ? round(($totalRateBps / $summary['peers_active_5m']) / 1000 / 1000, 2)
            : 0.0;

        $summary['top_peers'] = array_map(function (array $item) use ($totalRateBps) {
            $sharePercent = $totalRateBps > 0 ? round(($item['rate_bps'] / $totalRateBps) * 100, 2) : 0.0;

            return [
                'peer_name' => $item['peer_name'],
                'user_id' => $item['user_id'],
                'ip' => $item['ip'],
                'mbps' => $item['rate_mbps'],
                'share_percent' => $sharePercent,
            ];
        }, array_slice($topPeers, 0, self::TOP_PEERS_LIMIT));

        $topPeer = $summary['top_peers'][0] ?? null;
        if (is_array($topPeer)) {
            $summary['top_peer_name'] = (string) ($topPeer['peer_name'] ?? '');
            $summary['top_peer_user_id'] = isset($topPeer['user_id']) ? (int) $topPeer['user_id'] : null;
            $summary['top_peer_ip'] = $topPeer['ip'] ?? null;
            $summary['top_peer_mbps'] = isset($topPeer['mbps']) ? (float) $topPeer['mbps'] : null;
            $summary['top_peer_share_percent'] = isset($topPeer['share_percent']) ? (float) $topPeer['share_percent'] : null;
        }

        ServerAwgSummary::query()->updateOrCreate(
            ['server_id' => $serverId],
            $summary
        );
    }

    private function stripCidr(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return (string) explode('/', $value, 2)[0];
    }

    private function collectVlessTraffic(Server $server): int
    {
        if (!$this->hasVlessCredentials($server)) {
            return 0;
        }

        $processedPeers = 0;

        try {
            $client = new XuiClient((string) $server->url2, (string) $server->username2, (string) $server->password2, 20);
            $response = $client->inboundsList();
        } catch (\Throwable $e) {
            Log::warning('VPN traffic collect x-ui inbounds request failed: ' . $e->getMessage(), ['server_id' => $server->id]);
            return 0;
        }

        if (!(bool) ($response['success'] ?? false)) {
            Log::warning('VPN traffic collect x-ui returned not ok', [
                'server_id' => $server->id,
                'error' => (string) ($response['msg'] ?? 'unknown_error'),
            ]);
            return 0;
        }

        $inbounds = $response['obj'] ?? [];
        if (!is_array($inbounds)) {
            Log::warning('VPN traffic collect x-ui payload is invalid', ['server_id' => $server->id]);
            return 0;
        }

        foreach ($inbounds as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }

            $clientStats = $inbound['clientStats'] ?? null;
            if (!is_array($clientStats)) {
                continue;
            }

            foreach ($clientStats as $client) {
                if (!is_array($client)) {
                    continue;
                }

                $email = trim((string) ($client['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $rxBytes = max(0, (int) ($client['down'] ?? 0));
                $txBytes = max(0, (int) ($client['up'] ?? 0));
                $enabled = (bool) ($client['enable'] ?? ($inbound['enable'] ?? true));

                $this->processVlessPeer((int) $server->id, $email, $rxBytes, $txBytes, $enabled);
                $processedPeers++;
            }
        }

        return $processedPeers;
    }

    /**
     * @return array{
     *     map: array<string, array{
     *         server_status: string,
     *         public_key: string,
     *         ip: string,
     *         endpoint: string,
     *         endpoint_ip: string,
     *         endpoint_port: int|null,
     *         latest_handshake_epoch: int|null
     *     }>,
     *     available: bool
     * }
     */
    private function fetchAndStoreServerStatuses(VpnAgentClient $client, int $serverId): array
    {
        $map = [];
        $available = false;

        try {
            $response = $client->peersStatus();
        } catch (\Throwable $e) {
            Log::warning('VPN traffic collect peers-status request failed: ' . $e->getMessage(), ['server_id' => $serverId]);
            return ['map' => $map, 'available' => $available];
        }

        if (!(bool) ($response['ok'] ?? false)) {
            Log::warning('VPN traffic collect peers-status returned not ok', [
                'server_id' => $serverId,
                'error' => (string) ($response['error'] ?? 'unknown_error'),
            ]);
            return ['map' => $map, 'available' => $available];
        }

        $peers = $response['peers'] ?? [];
        if (!is_array($peers)) {
            Log::warning('VPN traffic collect peers-status payload is invalid', ['server_id' => $serverId]);
            return ['map' => $map, 'available' => $available];
        }
        $available = true;

        foreach ($peers as $peer) {
            if (!is_array($peer)) {
                continue;
            }

            $name = trim((string) ($peer['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $enabled = (bool) ($peer['enabled'] ?? false);
            $status = $enabled ? 'enabled' : 'disabled';
            $this->upsertServerState($serverId, $name, $peer, $status);

            $map[$name] = [
                'server_status' => $status,
                'public_key' => trim((string) ($peer['public_key'] ?? '')),
                'ip' => trim((string) ($peer['ip'] ?? '')),
                'endpoint' => trim((string) ($peer['endpoint'] ?? '')),
                'endpoint_ip' => trim((string) ($peer['endpoint_ip'] ?? '')),
                'endpoint_port' => isset($peer['endpoint_port']) && $peer['endpoint_port'] !== ''
                    ? (int) $peer['endpoint_port']
                    : null,
                'latest_handshake_epoch' => isset($peer['latest_handshake_epoch']) ? (int) $peer['latest_handshake_epoch'] : null,
            ];
        }

        return ['map' => $map, 'available' => $available];
    }

    /**
     * @param array<string, mixed> $peer
     */
    private function processPeer(int $serverId, string $name, array $peer, string $serverStatus): void
    {
        $now = Carbon::now();
        $date = $now->toDateString();
        $publicKey = trim((string) ($peer['public_key'] ?? ''));
        $ip = trim((string) ($peer['ip'] ?? ''));
        $endpoint = trim((string) ($peer['endpoint'] ?? ''));
        $endpointIp = trim((string) ($peer['endpoint_ip'] ?? ''));
        $endpointPort = isset($peer['endpoint_port']) && $peer['endpoint_port'] !== ''
            ? (int) $peer['endpoint_port']
            : null;
        $rxBytes = max(0, (int) ($peer['rx_bytes'] ?? 0));
        $txBytes = max(0, (int) ($peer['tx_bytes'] ?? 0));
        $subscription = $this->resolveSubscription($serverId, $name);
        $userId = $subscription ? (int) $subscription->user_id : null;
        $shouldAggregate = $subscription && $subscription->isLocallyActive() && $serverStatus === 'enabled';

        DB::transaction(function () use (
            $date,
            $endpoint,
            $endpointIp,
            $endpointPort,
            $ip,
            $name,
            $now,
            $publicKey,
            $rxBytes,
            $serverId,
            $txBytes,
            $userId,
            $shouldAggregate
        ) {
            $snapshot = VpnPeerTrafficSnapshot::query()
                ->where('server_id', $serverId)
                ->where('peer_name', $name)
                ->first();

            if ($snapshot) {
                $deltaRx = $rxBytes >= (int) $snapshot->rx_bytes ? $rxBytes - (int) $snapshot->rx_bytes : $rxBytes;
                $deltaTx = $txBytes >= (int) $snapshot->tx_bytes ? $txBytes - (int) $snapshot->tx_bytes : $txBytes;
            } else {
                $deltaRx = 0;
                $deltaTx = 0;
            }
            $deltaTotal = $deltaRx + $deltaTx;

            if ($shouldAggregate) {
                $daily = VpnPeerTrafficDaily::query()
                    ->where('server_id', $serverId)
                    ->where('peer_name', $name)
                    ->whereDate('date', $date)
                    ->first();
                if (!$daily) {
                    $daily = new VpnPeerTrafficDaily([
                        'date' => $date,
                        'server_id' => $serverId,
                        'peer_name' => $name,
                    ]);
                }
                if (!$daily->exists) {
                    $daily->rx_bytes_delta = 0;
                    $daily->tx_bytes_delta = 0;
                    $daily->total_bytes_delta = 0;
                }

                $daily->user_id = $userId;
                $daily->public_key = $publicKey !== '' ? $publicKey : $daily->public_key;
                $daily->ip = $ip !== '' ? $ip : $daily->ip;
                $daily->rx_bytes_delta = (int) $daily->rx_bytes_delta + $deltaRx;
                $daily->tx_bytes_delta = (int) $daily->tx_bytes_delta + $deltaTx;
                $daily->total_bytes_delta = (int) $daily->total_bytes_delta + $deltaTotal;
                $daily->save();
            }

            $shouldRecordEndpointEvent = Schema::hasTable('vpn_peer_endpoint_events')
                && $endpoint !== ''
                && (!$snapshot || (string) ($snapshot->endpoint ?? '') !== $endpoint);

            if ($shouldRecordEndpointEvent) {
                VpnPeerEndpointEvent::query()->create([
                    'server_id' => $serverId,
                    'user_id' => $userId,
                    'peer_name' => $name,
                    'public_key' => $publicKey !== '' ? $publicKey : null,
                    'endpoint' => $endpoint,
                    'endpoint_ip' => $endpointIp !== '' ? $endpointIp : null,
                    'endpoint_port' => $endpointPort,
                    'seen_at' => $now,
                ]);
            }

            $snapshotUpdate = [
                'user_id' => $userId,
                'public_key' => $publicKey !== '' ? $publicKey : null,
                'ip' => $ip !== '' ? $ip : null,
                'endpoint' => $endpoint !== '' ? $endpoint : null,
                'endpoint_ip' => $endpointIp !== '' ? $endpointIp : null,
                'endpoint_port' => $endpointPort,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'captured_at' => $now,
            ];
            if ($deltaTotal > 0) {
                $snapshotUpdate['last_seen_amnezia'] = $now;
            }

            VpnPeerTrafficSnapshot::query()->updateOrCreate(
                ['server_id' => $serverId, 'peer_name' => $name],
                $snapshotUpdate
            );
        });
    }

    private function processVlessPeer(int $serverId, string $name, int $rxBytes, int $txBytes, bool $enabled): void
    {
        $now = Carbon::now();
        $date = $now->toDateString();
        $subscription = $this->resolveSubscription($serverId, $name);
        $userId = $subscription ? (int) $subscription->user_id : null;
        $shouldAggregate = $subscription && $subscription->isLocallyActive() && $enabled;

        DB::transaction(function () use ($date, $name, $now, $rxBytes, $serverId, $txBytes, $userId, $shouldAggregate) {
            $snapshot = VpnPeerTrafficSnapshot::query()
                ->where('server_id', $serverId)
                ->where('peer_name', $name)
                ->first();

            if ($snapshot && $snapshot->vless_rx_bytes !== null) {
                $prevRx = (int) $snapshot->vless_rx_bytes;
                $deltaRx = $rxBytes >= $prevRx ? $rxBytes - $prevRx : $rxBytes;
            } else {
                $deltaRx = 0;
            }

            if ($snapshot && $snapshot->vless_tx_bytes !== null) {
                $prevTx = (int) $snapshot->vless_tx_bytes;
                $deltaTx = $txBytes >= $prevTx ? $txBytes - $prevTx : $txBytes;
            } else {
                $deltaTx = 0;
            }

            $deltaTotal = $deltaRx + $deltaTx;

            if ($shouldAggregate) {
                $daily = VpnPeerTrafficDaily::query()
                    ->where('server_id', $serverId)
                    ->where('peer_name', $name)
                    ->whereDate('date', $date)
                    ->first();

                if (!$daily) {
                    $daily = new VpnPeerTrafficDaily([
                        'date' => $date,
                        'server_id' => $serverId,
                        'peer_name' => $name,
                    ]);
                }

                if (!$daily->exists) {
                    $daily->rx_bytes_delta = (int) ($daily->rx_bytes_delta ?? 0);
                    $daily->tx_bytes_delta = (int) ($daily->tx_bytes_delta ?? 0);
                    $daily->total_bytes_delta = (int) ($daily->total_bytes_delta ?? 0);
                    $daily->vless_rx_bytes_delta = (int) ($daily->vless_rx_bytes_delta ?? 0);
                    $daily->vless_tx_bytes_delta = (int) ($daily->vless_tx_bytes_delta ?? 0);
                    $daily->vless_total_bytes_delta = (int) ($daily->vless_total_bytes_delta ?? 0);
                }

                if ($userId !== null) {
                    $daily->user_id = $userId;
                }
                $daily->vless_rx_bytes_delta = (int) $daily->vless_rx_bytes_delta + $deltaRx;
                $daily->vless_tx_bytes_delta = (int) $daily->vless_tx_bytes_delta + $deltaTx;
                $daily->vless_total_bytes_delta = (int) $daily->vless_total_bytes_delta + $deltaTotal;
                $daily->save();
            }

            $update = [
                'vless_rx_bytes' => $rxBytes,
                'vless_tx_bytes' => $txBytes,
                'vless_captured_at' => $now,
            ];
            if ($deltaTotal > 0) {
                $update['last_seen_vless'] = $now;
            }
            if ($userId !== null) {
                $update['user_id'] = $userId;
            }

            VpnPeerTrafficSnapshot::query()->updateOrCreate(
                ['server_id' => $serverId, 'peer_name' => $name],
                $update
            );
        });
    }

    /**
     * @param array<string, mixed> $peer
     */
    private function upsertServerState(int $serverId, string $name, array $peer, string $status): void
    {
        $publicKey = trim((string) ($peer['public_key'] ?? ''));
        $ip = trim((string) ($peer['ip'] ?? ''));
        $endpoint = trim((string) ($peer['endpoint'] ?? ''));
        $endpointIp = trim((string) ($peer['endpoint_ip'] ?? ''));
        $endpointPort = isset($peer['endpoint_port']) && $peer['endpoint_port'] !== ''
            ? (int) $peer['endpoint_port']
            : null;
        $lastHandshake = isset($peer['latest_handshake_epoch']) ? (int) $peer['latest_handshake_epoch'] : null;
        $subscription = $this->resolveSubscription($serverId, $name);

        VpnPeerServerState::query()->updateOrCreate(
            ['server_id' => $serverId, 'peer_name' => $name],
            [
                'user_id' => $subscription ? (int) $subscription->user_id : null,
                'public_key' => $publicKey !== '' ? $publicKey : null,
                'ip' => $ip !== '' ? $ip : null,
                'endpoint' => $endpoint !== '' ? $endpoint : null,
                'endpoint_ip' => $endpointIp !== '' ? $endpointIp : null,
                'endpoint_port' => $endpointPort,
                'server_status' => $status,
                'last_handshake_epoch' => $lastHandshake,
                'status_fetched_at' => Carbon::now(),
            ]
        );
    }

    private function resolveSubscription(int $serverId, string $name): ?UserSubscription
    {
        $cacheKey = $serverId . ':' . $name;
        if (array_key_exists($cacheKey, $this->subscriptionByPeerName)) {
            return $this->subscriptionByPeerName[$cacheKey];
        }

        $this->warmSubscriptionMap($serverId);

        return $this->subscriptionByPeerName[$cacheKey] ?? null;
    }

    private function warmSubscriptionMap(int $serverId): void
    {
        if ($this->subscriptionMapLoaded[$serverId] ?? false) {
            return;
        }

        $subscriptions = UserSubscription::query()
            ->where(function ($query) use ($serverId) {
                $query->where('file_path', 'like', '%_' . $serverId . '_%')
                    ->orWhere(function ($fallback) {
                        $fallback
                            ->where(function ($emptyPath) {
                                $emptyPath->whereNull('file_path')->orWhere('file_path', '');
                            })
                            ->where('connection_config', 'like', '%#%');
                    });
            })
            ->orderByDesc('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $peerName = $this->extractPeerNameForServer($subscription, $serverId);
            if ($peerName === null || $peerName === '') {
                continue;
            }

            $cacheKey = $serverId . ':' . $peerName;
            if (!array_key_exists($cacheKey, $this->subscriptionByPeerName)) {
                $this->subscriptionByPeerName[$cacheKey] = $subscription;
            }
        }

        $this->subscriptionMapLoaded[$serverId] = true;
    }

    private function extractPeerNameForServer(UserSubscription $subscription, int $serverId): ?string
    {
        $filePath = trim((string) ($subscription->file_path ?? ''));
        if ($filePath !== '') {
            return \App\Support\VpnPeerName::fromFilePath($filePath, $serverId);
        }

        return \App\Support\VpnPeerName::fromConnectionConfig($subscription->connection_config);
    }

    private function hasVlessCredentials(Server $server): bool
    {
        return trim((string) ($server->url2 ?? '')) !== ''
            && trim((string) ($server->username2 ?? '')) !== ''
            && trim((string) ($server->password2 ?? '')) !== '';
    }
}
