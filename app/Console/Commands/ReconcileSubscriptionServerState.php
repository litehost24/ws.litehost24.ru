<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\VpnPeerServerState;
use App\Services\VpnAgent\Node1Provisioner;
use App\Support\VpnPeerName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReconcileSubscriptionServerState extends Command
{
    protected $signature = 'subscriptions:reconcile-server-state
        {--server-id= : Limit reconciliation to one server}
        {--user-id= : Limit reconciliation to one user}
        {--peer-name= : Limit reconciliation to one peer name}
        {--dry-run : Show planned actions without enabling access}
        {--force : Ignore retry cooldown}';

    protected $description = 'Re-enable active subscriptions whose node1 peer is unexpectedly disabled on the server.';

    private const STATE_FRESH_FOR_MINUTES = 10;
    private const RETRY_COOLDOWN_MINUTES = 10;

    public function handle(Node1Provisioner $node1Provisioner): int
    {
        if (!Schema::hasTable('vpn_peer_server_states')) {
            $this->info('vpn_peer_server_states table is missing, nothing to reconcile.');
            return self::SUCCESS;
        }

        $candidates = $this->candidateSubscriptions();
        if ($candidates->isEmpty()) {
            $this->info('No active node1 subscriptions found for reconciliation.');
            return self::SUCCESS;
        }

        $states = VpnPeerServerState::query()
            ->whereIn('server_id', $candidates->pluck('server.id')->unique()->all())
            ->whereIn('peer_name', $candidates->pluck('peer_name')->unique()->all())
            ->get()
            ->keyBy(fn (VpnPeerServerState $state) => $this->stateKey((int) $state->server_id, (string) $state->peer_name));

        $freshCutoff = Carbon::now()->subMinutes(self::STATE_FRESH_FOR_MINUTES);
        $stats = [
            'planned' => 0,
            'reconciled' => 0,
            'failed' => 0,
            'skipped_no_state' => 0,
            'skipped_stale' => 0,
            'skipped_status' => 0,
            'skipped_cooldown' => 0,
        ];

        foreach ($candidates as $candidate) {
            $server = $candidate['server'];
            $subscription = $candidate['subscription'];
            $peerName = $candidate['peer_name'];
            $state = $states->get($this->stateKey((int) $server->id, $peerName));

            if (!$state) {
                $stats['skipped_no_state']++;
                continue;
            }

            if ((string) $state->server_status !== 'disabled') {
                $stats['skipped_status']++;
                continue;
            }

            $fetchedAt = $state->status_fetched_at;
            if (!$fetchedAt || $fetchedAt->lt($freshCutoff)) {
                $stats['skipped_stale']++;
                continue;
            }

            $cooldownKey = $this->cooldownKey((int) $server->id, $peerName);
            if (!$this->option('force') && Cache::has($cooldownKey)) {
                $stats['skipped_cooldown']++;
                continue;
            }

            $stats['planned']++;

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would reconcile user_id=%d sub_id=%d server_id=%d peer=%s',
                    (int) $subscription->user_id,
                    (int) $subscription->id,
                    (int) $server->id,
                    $peerName
                ));
                continue;
            }

            $node1Ok = false;
            $errors = [];

            try {
                $node1Provisioner->enableByName($server, $peerName);
                $node1Ok = true;
            } catch (\Throwable $e) {
                $errors[] = 'node1: ' . $e->getMessage();
            }

            Cache::put($cooldownKey, Carbon::now()->timestamp, Carbon::now()->addMinutes(self::RETRY_COOLDOWN_MINUTES));

            $context = [
                'user_id' => (int) $subscription->user_id,
                'user_subscription_id' => (int) $subscription->id,
                'subscription_id' => (int) $subscription->subscription_id,
                'server_id' => (int) $server->id,
                'peer_name' => $peerName,
            ];

            if ($node1Ok) {
                $stats['reconciled']++;
                Log::warning('Reconciled active subscription server state.', $context);
                continue;
            }

            $stats['failed']++;
            Log::error('Failed to reconcile active subscription server state.', $context + ['errors' => $errors]);
        }

        $this->info(sprintf(
            'planned=%d reconciled=%d failed=%d skipped(no_state=%d stale=%d status=%d cooldown=%d)',
            $stats['planned'],
            $stats['reconciled'],
            $stats['failed'],
            $stats['skipped_no_state'],
            $stats['skipped_stale'],
            $stats['skipped_status'],
            $stats['skipped_cooldown']
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{subscription: UserSubscription, server: Server, peer_name: string}>
     */
    private function candidateSubscriptions(): Collection
    {
        $userId = $this->option('user-id');
        $serverIdFilter = $this->option('server-id');
        $peerNameFilter = trim((string) $this->option('peer-name'));

        $connectedSubscriptions = UserSubscription::connectedQuery($userId ? (int) $userId : null)
            ->orderByDesc('id')
            ->get();

        $processedDeviceKeys = [];
        $candidates = collect();

        foreach ($connectedSubscriptions as $subscription) {
            if (!$subscription->isLocallyActive()) {
                continue;
            }

            $deviceKey = $subscription->cabinetDeviceKey();
            if (isset($processedDeviceKeys[$deviceKey])) {
                continue;
            }
            $processedDeviceKeys[$deviceKey] = true;

            [$server, $peerName] = $this->resolveTarget($subscription);
            if (!$server || $peerName === null || !$server->usesNode1Api()) {
                continue;
            }

            if ($serverIdFilter !== null && (int) $server->id !== (int) $serverIdFilter) {
                continue;
            }

            if ($peerNameFilter !== '' && $peerName !== $peerNameFilter) {
                continue;
            }

            $candidates->push([
                'subscription' => $subscription,
                'server' => $server,
                'peer_name' => $peerName,
            ]);
        }

        return $candidates;
    }

    /**
     * @return array{0: Server|null, 1: string|null}
     */
    private function resolveTarget(UserSubscription $subscription): array
    {
        $filePath = trim((string) ($subscription->file_path ?? ''));
        if ($filePath === '') {
            return [null, null];
        }

        $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
        $parts = explode('_', $base);
        if (!isset($parts[2])) {
            return [null, null];
        }

        $serverId = (int) $parts[2];
        if ($serverId <= 0) {
            return [null, null];
        }

        $server = Server::query()->find($serverId);
        if (!$server) {
            return [null, null];
        }

        $peerName = VpnPeerName::fromSubscription($subscription, $serverId);
        if ($peerName === null || $peerName === '') {
            return [null, null];
        }

        return [$server, $peerName];
    }

    private function stateKey(int $serverId, string $peerName): string
    {
        return $serverId . ':' . $peerName;
    }

    private function cooldownKey(int $serverId, string $peerName): string
    {
        return 'subscriptions:reconcile-server-state:' . $serverId . ':' . $peerName;
    }
}
