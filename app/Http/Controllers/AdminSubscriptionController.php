<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionMigration;
use App\Models\SubscriptionMigrationItem;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use App\Models\User;
use App\Models\Payment;
use App\Models\Server;
use App\Models\SiteBanner;
use App\Models\VpnEndpointNetwork;
use App\Models\VpnPeerServerState;
use App\Models\VpnPeerTrafficDaily;
use App\Models\VpnPeerTrafficSnapshot;
use App\Services\VpnEndpointNetworkResolver;
use App\Services\VpnAgent\SubscriptionPeerOperator;
use App\Services\VpnAgent\SubscriptionVpnAccessModeSwitcher;
use App\Support\AmneziaSharingDetector;
use App\Support\SubscriptionBundleMeta;
use App\Support\VpnPeerName;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminSubscriptionController extends Controller
{
    public function index(): View
    {
        $sortBy = request()->get('sort_by', 'created_at');
        $sortOrder = request()->get('sort_order', 'desc');

        $allowedSortFields = [
            'user_id',
            'user.name',
            'subscription_id',
            'subscription.name',
            'price',
            'end_date',
            'action',
            'is_rebilling',
            'file_path',
            'created_at',
            'balance',
            'is_active'
        ];

        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        $displayRowIdsQuery = UserSubscription::query()
            ->select(DB::raw('COALESCE(MAX(CASE WHEN is_processed = 1 THEN id END), MAX(id))'))
            ->groupBy('user_id', 'subscription_id');

        $latestUserSubscriptionsQuery = UserSubscription::whereIn('user_subscriptions.id', $displayRowIdsQuery)
            ->with(['user' => function ($query) {
                $query->withCount('referrals');
            }, 'user.referrer', 'subscription']);

        if ($sortBy === 'user.name') {
            $latestUserSubscriptionsQuery->join('users', 'user_subscriptions.user_id', '=', 'users.id')
                ->orderBy('users.name', $sortOrder)
                ->select('user_subscriptions.*');
        } elseif ($sortBy === 'subscription.name') {
            $latestUserSubscriptionsQuery->join('subscriptions', 'user_subscriptions.subscription_id', '=', 'subscriptions.id')
                ->orderBy('subscriptions.name', $sortOrder)
                ->select('user_subscriptions.*');
        } elseif (!in_array($sortBy, ['balance', 'is_active'])) {
            $latestUserSubscriptionsQuery->orderBy($sortBy, $sortOrder);
        } else {
            $latestUserSubscriptionsQuery->orderBy('created_at', 'desc');
        }

        $statusFilter = request()->get('status');

        $latestUserSubscriptions = $latestUserSubscriptionsQuery->get();
        $deleteStatsByPair = UserSubscription::query()
            ->select('user_id', 'subscription_id', DB::raw('COUNT(*) as history_count'), DB::raw('MAX(id) as latest_id'))
            ->groupBy('user_id', 'subscription_id')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (int) $row->subscription_id;
            });

        if ($sortBy === 'user.name' || $sortBy === 'subscription.name') {
            $latestUserSubscriptions = $latestUserSubscriptions->load(['user' => function ($query) {
                $query->withCount('referrals');
            }, 'user.referrer', 'subscription']);
        }

        foreach ($latestUserSubscriptions as $userSub) {
            $userSub->is_active = $this->isSubscriptionActive($userSub);
            $balanceComponent = new \App\Models\components\Balance();
            $userSub->balance = $balanceComponent->getBalance($userSub->user_id);
            $deleteCheck = $this->deleteEligibility(
                $userSub,
                $deleteStatsByPair->get((int) $userSub->user_id . ':' . (int) $userSub->subscription_id)
            );
            $userSub->can_admin_delete = $deleteCheck['allowed'];
            $userSub->admin_delete_reason = $deleteCheck['reason'];
        }

        $paidNoSubUsers = collect([]);
        if ($statusFilter === 'paid_no_sub') {
            $paidNoSubUsers = User::query()
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.role',
                    'users.created_at',
                    DB::raw('COUNT(payments.id) as payment_count'),
                    DB::raw('SUM(payments.amount) as payment_total'),
                    DB::raw('MAX(payments.created_at) as last_payment_at')
                )
                ->join('payments', 'payments.user_id', '=', 'users.id')
                ->leftJoin('user_subscriptions', 'user_subscriptions.user_id', '=', 'users.id')
                ->whereNull('user_subscriptions.id')
                ->where('payments.amount', '>', 0)
                ->groupBy('users.id', 'users.name', 'users.email', 'users.role', 'users.created_at')
                ->orderByDesc('last_payment_at')
                ->get();
        }

        if ($statusFilter === 'active') {
            $latestUserSubscriptions = $latestUserSubscriptions->filter(function ($item) {
                return (bool) $item->is_active;
            })->values();
        } elseif ($statusFilter === 'inactive') {
            $latestUserSubscriptions = $latestUserSubscriptions->filter(function ($item) {
                return !(bool) $item->is_active;
            })->values();
        }


        if ($sortBy === 'balance') {
            $latestUserSubscriptions = $latestUserSubscriptions->sortBy(function ($item) {
                return $item->balance;
            }, SORT_REGULAR, $sortOrder === 'desc');
        } elseif ($sortBy === 'is_active') {
            $latestUserSubscriptions = $latestUserSubscriptions->sortBy(function ($item) {
                return $item->is_active;
            }, SORT_REGULAR, $sortOrder === 'desc');
        }

        $latestServerId = Server::orderBy('id', 'desc')->value('id');
        $servers = Server::orderBy('id', 'asc')->get();
        $selectedServerId = (int) request()->get('server_id', $latestServerId);
        if ($selectedServerId && $servers->where('id', $selectedServerId)->isEmpty()) {
            $selectedServerId = (int) $latestServerId;
        }

        foreach ($latestUserSubscriptions as $userSub) {
            $resolvedServerId = $userSub->resolveServerId();
            $userSub->resolved_server_id = $resolvedServerId;
            $userSub->peer_name = VpnPeerName::fromSubscription($userSub, $resolvedServerId);
        }

        $latestUserSubscriptions = $this->collapseDuplicatePeerRows($latestUserSubscriptions);
        $activeDisplayPeerKeys = $latestUserSubscriptions
            ->filter(fn (UserSubscription $subscription) => (bool) ($subscription->is_active ?? false))
            ->map(function (UserSubscription $subscription) {
                $userId = (int) ($subscription->user_id ?? 0);
                $peerName = trim((string) ($subscription->peer_name ?? ''));
                if ($userId <= 0 || $peerName === '') {
                    return null;
                }

                return $userId . ':' . $peerName;
            })
            ->filter()
            ->unique()
            ->flip();

        $historyServerIdsByPairPeer = collect([]);
        $pairKeys = $latestUserSubscriptions
            ->map(function ($subscription) {
                $userId = (int) ($subscription->user_id ?? 0);
                $subscriptionId = (int) ($subscription->subscription_id ?? 0);

                if ($userId <= 0 || $subscriptionId <= 0) {
                    return null;
                }

                return $userId . ':' . $subscriptionId;
            })
            ->filter()
            ->unique()
            ->values();

        if ($pairKeys->isNotEmpty()) {
            $userIds = $latestUserSubscriptions
                ->pluck('user_id')
                ->filter(fn ($value) => (int) $value > 0)
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values();
            $subscriptionIds = $latestUserSubscriptions
                ->pluck('subscription_id')
                ->filter(fn ($value) => (int) $value > 0)
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values();
            $pairKeyLookup = array_fill_keys($pairKeys->all(), true);
            $historyServerIds = [];

            UserSubscription::query()
                ->select('user_id', 'subscription_id', 'server_id', 'file_path', 'connection_config')
                ->whereIn('user_id', $userIds->all())
                ->whereIn('subscription_id', $subscriptionIds->all())
                ->get()
                ->each(function (UserSubscription $historyRow) use (&$historyServerIds, $pairKeyLookup) {
                    $pairKey = (int) $historyRow->user_id . ':' . (int) $historyRow->subscription_id;
                    if (!isset($pairKeyLookup[$pairKey])) {
                        return;
                    }

                    $serverId = $historyRow->resolveServerId();
                    $peerName = VpnPeerName::fromSubscription($historyRow, $serverId);
                    if ($serverId === null || !is_string($peerName) || trim($peerName) === '') {
                        return;
                    }

                    $historyKey = $pairKey . ':' . $peerName;
                    $historyServerIds[$historyKey][] = (int) $serverId;
                });

            $historyServerIdsByPairPeer = collect($historyServerIds)->map(function (array $serverIds) {
                return collect($serverIds)
                    ->filter(fn ($value) => (int) $value > 0)
                    ->map(fn ($value) => (int) $value)
                    ->unique()
                    ->values();
            });
        }

        $serverStateByServerPeer = collect([]);
        $resolvedServerIds = $latestUserSubscriptions
            ->pluck('resolved_server_id')
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();
        $resolvedPeerNames = $latestUserSubscriptions
            ->pluck('peer_name')
            ->filter(fn ($value) => !empty($value))
            ->unique()
            ->values();

        if ($resolvedServerIds->isNotEmpty() && $resolvedPeerNames->isNotEmpty() && Schema::hasTable('vpn_peer_server_states')) {
            try {
                $serverStateByServerPeer = VpnPeerServerState::query()
                    ->whereIn('server_id', $resolvedServerIds->all())
                    ->whereIn('peer_name', $resolvedPeerNames->all())
                    ->get()
                    ->keyBy(function ($row) {
                        return (int) $row->server_id . ':' . (string) $row->peer_name;
                    });
            } catch (\Throwable $e) {
                report($e);
            }
        }

        foreach ($latestUserSubscriptions as $userSub) {
            $peerName = (string) ($userSub->peer_name ?? '');
            $resolvedServerId = (int) ($userSub->resolved_server_id ?? 0);
            $displayPeerKey = (int) ($userSub->user_id ?? 0) . ':' . $peerName;
            $isShadowedByActivePeer = !(bool) $userSub->is_active
                && $peerName !== ''
                && $activeDisplayPeerKeys->has($displayPeerKey);
            $state = ($peerName !== '' && $resolvedServerId > 0)
                ? $serverStateByServerPeer->get($resolvedServerId . ':' . $peerName)
                : null;
            $serverStatus = $state?->server_status ?: null;
            $historyKey = (int) ($userSub->user_id ?? 0) . ':' . (int) ($userSub->subscription_id ?? 0) . ':' . $peerName;
            $historyServerIds = $historyServerIdsByPairPeer->get($historyKey, collect([]));
            $hasSwitchedServerHistory = $resolvedServerId > 0
                && $historyServerIds->contains(fn ($serverId) => (int) $serverId !== $resolvedServerId);

            if (!$serverStatus) {
                $serverStatus = ($peerName !== '' && !$hasSwitchedServerHistory) ? 'missing' : 'unknown';
            }

            if ($isShadowedByActivePeer) {
                $serverStatus = 'shadowed';
            }

            $userSub->is_shadowed_by_active_peer = $isShadowedByActivePeer;
            $userSub->server_status = $serverStatus;
            $userSub->effective_status = $serverStatus === 'enabled'
                ? 'working'
                : ($serverStatus === 'unknown' || $serverStatus === 'shadowed' ? 'unknown' : 'stopped');
            $userSub->has_server_status_conflict = !$isShadowedByActivePeer && (
                ((bool) $userSub->is_active && $serverStatus !== 'enabled')
                || (!(bool) $userSub->is_active && $serverStatus === 'enabled')
            );
            $userSub->endpoint_ip = $state?->endpoint_ip ?: null;
            $userSub->endpoint_seen_at = $state?->status_fetched_at ?: null;
        }

        UserSubscription::attachTrafficTotals($latestUserSubscriptions);

        $onlineByPeerKey = collect([]);
        $dualActiveByPeerKey = collect([]);
        if ($resolvedServerIds->isNotEmpty() && $resolvedPeerNames->isNotEmpty() && Schema::hasTable('vpn_peer_traffic_snapshots')) {
            try {
                $userIds = $latestUserSubscriptions->pluck('user_id')->filter(fn ($v) => !empty($v))->unique()->values();
                if ($userIds->isNotEmpty()) {
                    $onlineCutoff = Carbon::now()->subMinutes(5);
                    $onlineByPeerKey = VpnPeerTrafficSnapshot::query()
                        ->select('server_id', 'user_id', 'peer_name')
                        ->whereIn('server_id', $resolvedServerIds->all())
                        ->whereIn('user_id', $userIds->all())
                        ->whereIn('peer_name', $resolvedPeerNames->all())
                        ->where(function ($q) use ($onlineCutoff) {
                            $q->where('last_seen_amnezia', '>=', $onlineCutoff)
                                ->orWhere('last_seen_vless', '>=', $onlineCutoff);
                        })
                        ->get()
                        ->keyBy(function ($row) {
                            return (int) $row->server_id . ':' . (int) $row->user_id . ':' . (string) $row->peer_name;
                        });

                    $dualActiveByPeerKey = VpnPeerTrafficSnapshot::query()
                        ->select('server_id', 'user_id', 'peer_name')
                        ->whereIn('server_id', $resolvedServerIds->all())
                        ->whereIn('user_id', $userIds->all())
                        ->whereIn('peer_name', $resolvedPeerNames->all())
                        ->where('last_seen_amnezia', '>=', $onlineCutoff)
                        ->where('last_seen_vless', '>=', $onlineCutoff)
                        ->get()
                        ->keyBy(function ($row) {
                            return (int) $row->server_id . ':' . (int) $row->user_id . ':' . (string) $row->peer_name;
                        });
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $sharingByPeerKey = [];
        foreach ($resolvedServerIds as $resolvedServerId) {
            $items = $latestUserSubscriptions->filter(function ($subscription) use ($resolvedServerId) {
                return (int) ($subscription->resolved_server_id ?? 0) === (int) $resolvedServerId;
            })->values();

            foreach (AmneziaSharingDetector::forSubscriptions($items, (int) $resolvedServerId) as $peerKey => $risk) {
                $sharingByPeerKey[(int) $resolvedServerId . ':' . (string) $peerKey] = $risk;
            }
        }


        foreach ($latestUserSubscriptions as $userSub) {
            $peerName = (string) ($userSub->peer_name ?? '');
            $resolvedServerId = (int) ($userSub->resolved_server_id ?? 0);
            $peerKey = (int) ($userSub->user_id ?? 0) . ':' . $peerName;
            $peerKeyByServer = $resolvedServerId . ':' . $peerKey;
            $sharingRisk = ($peerName !== '' && $resolvedServerId > 0) ? ($sharingByPeerKey[$peerKeyByServer] ?? null) : null;
            $userSub->sharing_risk_level = $sharingRisk['level'] ?? null;
            $userSub->sharing_risk_changes = $sharingRisk['changes'] ?? 0;
            $userSub->sharing_risk_distinct_endpoints = $sharingRisk['distinct_endpoints'] ?? 0;
            $userSub->sharing_risk_tooltip = $sharingRisk['tooltip'] ?? null;
            $userSub->is_online = (bool) $userSub->is_active && $peerName !== '' && $resolvedServerId > 0 && $onlineByPeerKey->has($peerKeyByServer);
            $userSub->is_dual_protocol_recent = (bool) $userSub->is_active && $peerName !== '' && $resolvedServerId > 0 && $dualActiveByPeerKey->has($peerKeyByServer);
        }

        [$userEndpointNetworksByUserId, $userEndpointNetworkSummary] = $this->resolveUserEndpointNetworks($latestUserSubscriptions);

        $migration = SubscriptionMigration::orderBy('id', 'desc')->first();
        $migrationErrors = collect();
        if ($migration) {
            $migrationErrors = SubscriptionMigrationItem::where('subscription_migration_id', $migration->id)
                ->orderBy('id', 'desc')
                ->limit(10)
                ->get();
        }

        $usersGrouped = $latestUserSubscriptions->groupBy('user_id');
        $onlineUsers = $usersGrouped->filter(function ($userSubs) {
            return $userSubs->contains(function ($sub) {
                return (bool) $sub->is_online;
            });
        })->count();
        $totalUsers = $usersGrouped->count();
        $activeUsers = $usersGrouped->filter(function ($userSubs) {
            return $userSubs->contains(function ($sub) {
                return (bool) $sub->is_active;
            });
        })->count();
        $inactiveUsers = max(0, $totalUsers - $activeUsers);
        $activeSubscriptions = $latestUserSubscriptions->filter(function ($sub) {
            return (bool) $sub->is_active;
        })->count();
        $totalBalance = Payment::sum('amount')
            - UserSubscription::sum('price')
            - (Schema::hasTable('user_subscription_topups')
                ? UserSubscriptionTopup::sum('price')
                : 0);
        $siteBanner = SiteBanner::first();

        return view('admin.subscriptions.index', [
            'userSubscriptions' => $latestUserSubscriptions,
            'statusFilter' => $statusFilter,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'latestServerId' => $latestServerId,
            'servers' => $servers,
            'selectedServerId' => $selectedServerId,
            'migration' => $migration,
            'migrationErrors' => $migrationErrors,
            'paidNoSubUsers' => $paidNoSubUsers,
            'totalUsers' => $totalUsers,
            'onlineUsers' => $onlineUsers,
            'activeUsers' => $activeUsers,
            'inactiveUsers' => $inactiveUsers,
            'activeSubscriptions' => $activeSubscriptions,
            'totalBalance' => $totalBalance,
            'siteBanner' => $siteBanner,
            'userEndpointNetworksByUserId' => $userEndpointNetworksByUserId,
            'userEndpointNetworkSummary' => $userEndpointNetworkSummary,
        ]);
    }

    private function collapseDuplicatePeerRows(Collection $subscriptions): Collection
    {
        $latestIdByPeerKey = [];

        foreach ($subscriptions as $subscription) {
            $peerKey = $this->subscriptionPeerDisplayKey($subscription);
            if ($peerKey === null) {
                continue;
            }

            $currentId = (int) ($subscription->id ?? 0);
            if (!isset($latestIdByPeerKey[$peerKey]) || $currentId > $latestIdByPeerKey[$peerKey]) {
                $latestIdByPeerKey[$peerKey] = $currentId;
            }
        }

        return $subscriptions
            ->filter(function (UserSubscription $subscription) use ($latestIdByPeerKey) {
                $peerKey = $this->subscriptionPeerDisplayKey($subscription);
                if ($peerKey === null) {
                    return true;
                }

                return (int) ($subscription->id ?? 0) === (int) ($latestIdByPeerKey[$peerKey] ?? 0);
            })
            ->values();
    }

    private function subscriptionPeerDisplayKey(UserSubscription $subscription): ?string
    {
        $userId = (int) ($subscription->user_id ?? 0);
        $peerName = trim((string) ($subscription->peer_name ?? ''));
        $isActive = (bool) ($subscription->is_active ?? false);

        if (!$isActive || $userId <= 0 || $peerName === '') {
            return null;
        }

        // Collapse only currently active duplicate peers; keep inactive slots visible in admin.
        return $userId . ':' . $peerName;
    }

    /**
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveUserEndpointNetworks(Collection $subscriptions): array
    {
        $emptySummary = [
            'fresh_users_total' => 0,
            'mobile_count' => 0,
            'fixed_count' => 0,
            'hosting_count' => 0,
            'unknown_count' => 0,
            'mobile_percent' => 0.0,
            'fixed_percent' => 0.0,
            'hosting_percent' => 0.0,
            'unknown_percent' => 0.0,
            'top_mobile' => [],
            'top_fixed' => [],
        ];

        if ($subscriptions->isEmpty() || !Schema::hasTable('vpn_endpoint_networks')) {
            return [collect([]), $emptySummary];
        }

        $freshCutoff = Carbon::now()->subDay();
        $allEndpointIps = $subscriptions
            ->map(fn ($subscription) => VpnEndpointNetworkResolver::normalizeIp((string) ($subscription->endpoint_ip ?? '')))
            ->filter()
            ->unique()
            ->values();

        $profilesByIp = $allEndpointIps->isNotEmpty()
            ? VpnEndpointNetwork::query()
                ->whereIn('endpoint_ip', $allEndpointIps->all())
                ->get()
                ->keyBy('endpoint_ip')
            : collect([]);

        foreach ($subscriptions as $subscription) {
            $endpointIp = VpnEndpointNetworkResolver::normalizeIp((string) ($subscription->endpoint_ip ?? ''));
            /** @var VpnEndpointNetwork|null $profile */
            $profile = $endpointIp ? $profilesByIp->get($endpointIp) : null;
            $seenAt = $subscription->endpoint_seen_at instanceof Carbon ? $subscription->endpoint_seen_at : null;
            $networkType = $profile?->network_type ?: 'unknown';

            $subscription->endpoint_network_type = $networkType;
            $subscription->endpoint_network_type_label = VpnEndpointNetworkResolver::networkTypeLabel($networkType);
            $subscription->endpoint_network_operator_label = $profile?->operator_label ?: null;
            $subscription->endpoint_network_as_name = $profile?->as_name ?: null;
            $subscription->endpoint_network_as_number = $profile?->as_number ? (int) $profile->as_number : null;
            $subscription->endpoint_network_is_fresh = $seenAt instanceof Carbon && $seenAt->greaterThanOrEqualTo($freshCutoff);
        }

        $latestStateByUserId = $subscriptions
            ->groupBy('user_id')
            ->map(function (Collection $items) use ($freshCutoff) {
                $sorted = $items->sortByDesc(function ($subscription) {
                    $seenAt = $subscription->endpoint_seen_at ?? null;

                    return $seenAt instanceof Carbon ? $seenAt->getTimestamp() : 0;
                })->values();

                $fresh = $sorted->first(function ($subscription) use ($freshCutoff) {
                    $ip = VpnEndpointNetworkResolver::normalizeIp((string) ($subscription->endpoint_ip ?? ''));
                    $seenAt = $subscription->endpoint_seen_at ?? null;

                    return $ip !== null && $seenAt instanceof Carbon && $seenAt->greaterThanOrEqualTo($freshCutoff);
                });

                if ($fresh) {
                    return $fresh;
                }

                return $sorted->first(function ($subscription) {
                    return VpnEndpointNetworkResolver::normalizeIp((string) ($subscription->endpoint_ip ?? '')) !== null;
                });
            })
            ->filter();

        if ($latestStateByUserId->isEmpty()) {
            return [collect([]), $emptySummary];
        }

        $userNetworks = $latestStateByUserId->map(function ($subscription) use ($profilesByIp, $freshCutoff) {
            $endpointIp = VpnEndpointNetworkResolver::normalizeIp((string) ($subscription->endpoint_ip ?? ''));
            /** @var VpnEndpointNetwork|null $profile */
            $profile = $endpointIp ? $profilesByIp->get($endpointIp) : null;
            $seenAt = $subscription->endpoint_seen_at instanceof Carbon ? $subscription->endpoint_seen_at : null;
            $networkType = $profile?->network_type ?: 'unknown';

            return [
                'endpoint_ip' => $endpointIp,
                'network_type' => $networkType,
                'network_type_label' => VpnEndpointNetworkResolver::networkTypeLabel($networkType),
                'operator_label' => $profile?->operator_label ?: null,
                'as_name' => $profile?->as_name ?: null,
                'as_number' => $profile?->as_number ? (int) $profile->as_number : null,
                'seen_at' => $seenAt,
                'is_fresh' => $seenAt instanceof Carbon && $seenAt->greaterThanOrEqualTo($freshCutoff),
            ];
        });

        return [$userNetworks, $this->buildUserEndpointNetworkSummary($userNetworks)];
    }

    /**
     * @param Collection<int, array<string, mixed>> $userNetworks
     * @return array<string, mixed>
     */
    private function buildUserEndpointNetworkSummary(Collection $userNetworks): array
    {
        $summary = [
            'fresh_users_total' => 0,
            'mobile_count' => 0,
            'fixed_count' => 0,
            'hosting_count' => 0,
            'unknown_count' => 0,
            'mobile_percent' => 0.0,
            'fixed_percent' => 0.0,
            'hosting_percent' => 0.0,
            'unknown_percent' => 0.0,
            'top_mobile' => [],
            'top_fixed' => [],
        ];

        $mobileOperators = [];
        $fixedOperators = [];

        foreach ($userNetworks as $network) {
            if (!(bool) ($network['is_fresh'] ?? false)) {
                continue;
            }

            $summary['fresh_users_total']++;
            $type = (string) ($network['network_type'] ?? 'unknown');
            if (!in_array($type, ['mobile', 'fixed', 'hosting'], true)) {
                $type = 'unknown';
            }

            $summary[$type . '_count']++;

            $operatorLabel = trim((string) ($network['operator_label'] ?? ''));
            if ($operatorLabel === '') {
                continue;
            }

            if ($type === 'mobile') {
                $mobileOperators[$operatorLabel] = ($mobileOperators[$operatorLabel] ?? 0) + 1;
            } elseif ($type === 'fixed') {
                $fixedOperators[$operatorLabel] = ($fixedOperators[$operatorLabel] ?? 0) + 1;
            }
        }

        $total = max(1, (int) $summary['fresh_users_total']);
        $summary['mobile_percent'] = round(((int) $summary['mobile_count']) * 100 / $total, 1);
        $summary['fixed_percent'] = round(((int) $summary['fixed_count']) * 100 / $total, 1);
        $summary['hosting_percent'] = round(((int) $summary['hosting_count']) * 100 / $total, 1);
        $summary['unknown_percent'] = round(((int) $summary['unknown_count']) * 100 / $total, 1);
        $summary['top_mobile'] = VpnEndpointNetworkResolver::topOperators($mobileOperators);
        $summary['top_fixed'] = VpnEndpointNetworkResolver::topOperators($fixedOperators);

        return $summary;
    }

    public function migrate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'batch_size' => 'required|integer|min:1|max:1000',
            'resume' => 'nullable|boolean',
            'server_id' => 'required|integer|exists:servers,id',
        ]);

        $migration = SubscriptionMigration::where('status', 'running')->orderBy('id', 'desc')->first();
        $resume = $request->boolean('resume');
        $serverId = (int) $data['server_id'];

        if (!$migration && !$resume) {
            $migration = SubscriptionMigration::create([
                'status' => 'running',
                'server_id' => $serverId,
                'batch_size' => (int) $data['batch_size'],
                'started_at' => Carbon::now(),
            ]);
        } elseif ($migration && !$resume) {
            $migration->update([
                'batch_size' => (int) $data['batch_size'],
                'server_id' => $serverId,
            ]);
        }

        return redirect()->back()->with('subscription-success', '  .    .');
    }



    public function status(): JsonResponse
    {
        $migration = SubscriptionMigration::orderBy('id', 'desc')->first();
        $latestServerId = Server::orderBy('id', 'desc')->value('id');

        return response()->json([
            'migration' => $migration ? [
                'status' => $migration->status,
                'server_id' => $migration->server_id,
                'processed_count' => $migration->processed_count,
                'error_count' => $migration->error_count,
                'batch_size' => $migration->batch_size,
            ] : null,
            'latest_server_id' => $latestServerId,
        ]);
    }
    public function userDetails(User $user): JsonResponse
    {
        // In this project payments are created only from successful payment hook callbacks.
        $payments = Payment::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'amount', 'order_name', 'created_at']);

        $chargeRows = UserSubscription::where('user_id', $user->id)
            ->with('subscription:id,name')
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'subscription_id',
                'price',
                'action',
                'is_processed',
                'is_rebilling',
                'end_date',
                'created_at',
            ]);

        $totalPayments = (int) $payments->sum('amount');
        $totalCharges = (int) $chargeRows->sum('price')
            + (Schema::hasTable('user_subscription_topups')
                ? (int) UserSubscriptionTopup::where('user_id', $user->id)->sum('price')
                : 0);
        $balance = (new \App\Models\components\Balance())->getBalance($user->id);

        $latestRebillingRows = $chargeRows
            ->groupBy('subscription_id')
            ->map(function ($rows) {
                return $rows->first();
            })
            ->filter(function ($row) {
                return (bool) $row->is_rebilling;
            })
            ->values()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'subscription_id' => $row->subscription_id,
                    'subscription_name' => $row->subscription?->name ?? 'N/A',
                    'price' => (int) $row->price,
                    'price_rub' => number_format(((int) $row->price) / 100, 2, '.', ''),
                    'end_date' => $row->end_date,
                    'is_processed' => (bool) $row->is_processed,
                    'action' => $row->action,
                ];
            });

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'registered_at' => optional($user->created_at)->toDateTimeString(),
                'referrals_count' => $user->referrals()->count(),
                'referrer' => $user->referrer ? [
                    'id' => $user->referrer->id,
                    'name' => $user->referrer->name,
                ] : null,
            ],
            'summary' => [
                'total_payments' => $totalPayments,
                'total_payments_rub' => number_format($totalPayments / 100, 2, '.', ''),
                'total_charges' => $totalCharges,
                'total_charges_rub' => number_format($totalCharges / 100, 2, '.', ''),
                'balance' => $balance,
                'balance_rub' => number_format($balance / 100, 2, '.', ''),
            ],
            'payments' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => (int) $payment->amount,
                    'amount_rub' => number_format(((int) $payment->amount) / 100, 2, '.', ''),
                    'order_name' => $payment->order_name,
                    'created_at' => optional($payment->created_at)->toDateTimeString(),
                ];
            })->values(),
            'charges' => $chargeRows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'subscription_id' => $row->subscription_id,
                    'subscription_name' => $row->subscription?->name ?? 'N/A',
                    'price' => (int) $row->price,
                    'price_rub' => number_format(((int) $row->price) / 100, 2, '.', ''),
                    'action' => $row->action,
                    'is_processed' => (bool) $row->is_processed,
                    'is_rebilling' => (bool) $row->is_rebilling,
                    'end_date' => $row->end_date,
                    'created_at' => optional($row->created_at)->toDateTimeString(),
                ];
            })->values(),
            'rebilling' => $latestRebillingRows,
        ]);
    }
    public function stop(): RedirectResponse
    {
        $migration = SubscriptionMigration::where('status', 'running')->orderBy('id', 'desc')->first();
        if ($migration) {
            $migration->update([
                'status' => 'stopped',
                'finished_at' => Carbon::now(),
            ]);
        }

        return redirect()->back()->with('subscription-success', '.');
    }

    public function destroyUserSubscription(UserSubscription $userSubscription): RedirectResponse
    {
        $deleteCheck = $this->deleteEligibility($userSubscription);
        if (!(bool) $deleteCheck['allowed']) {
            return redirect()->back()->with('subscription-error', $deleteCheck['reason'] ?: 'Удаление этой подписки запрещено.');
        }

        [$ok, $error] = $this->disableSubscriptionOnServer($userSubscription);
        if (!$ok) {
            return redirect()->back()->with('subscription-error', 'Не удалось остановить подписку на сервере: ' . $error);
        }

        $userSubscription->delete();

        return redirect()->back()->with('subscription-success', 'Подписка остановлена и удалена.');
    }

    public function switchVpnAccessMode(
        Request $request,
        UserSubscription $userSubscription,
        SubscriptionVpnAccessModeSwitcher $switcher
    ): RedirectResponse
    {
        $data = $request->validate([
            'vpn_access_mode' => ['required', 'string'],
        ]);

        if (!$userSubscription->canSwitchVpnAccessMode()) {
            return redirect()->back()->with('subscription-error', 'Для этой подписки смена типа подключения недоступна.');
        }

        try {
            $switcher->switch($userSubscription, (string) $data['vpn_access_mode']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with('subscription-error', 'Не удалось сменить тип подключения: ' . $e->getMessage());
        }

        return redirect()->back()->with('subscription-success', 'Тип подключения подписки изменён. Старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг.');
    }

    private function isSubscriptionActive(
        $userSubscription
    ): bool
    {
        if (!$userSubscription) {
            return false;
        }

        return $userSubscription->isLocallyActive();
    }

    private function deleteEligibility(UserSubscription $userSubscription, $pairStat = null): array
    {
        $pairStat = $pairStat ?: UserSubscription::query()
            ->select(DB::raw('COUNT(*) as history_count'), DB::raw('MAX(id) as latest_id'))
            ->where('user_id', (int) $userSubscription->user_id)
            ->where('subscription_id', (int) $userSubscription->subscription_id)
            ->first();

        if (!$pairStat) {
            return ['allowed' => false, 'reason' => 'Не найдена история подписки.'];
        }

        if ((int) ($pairStat->latest_id ?? 0) !== (int) $userSubscription->id) {
            return ['allowed' => false, 'reason' => 'Удалять можно только последнюю запись по подписке.'];
        }

        if ((int) ($pairStat->history_count ?? 0) !== 1) {
            return ['allowed' => false, 'reason' => 'Удаление доступно только для ошибочно заведённой подписки без истории списаний и операций.'];
        }

        if ((string) ($userSubscription->action ?? '') !== 'create') {
            return ['allowed' => false, 'reason' => 'Удалять можно только исходно заведённую подписку.'];
        }

        [$ok, $error] = $this->resolveSubscriptionServerTarget($userSubscription);
        if (!$ok) {
            return ['allowed' => false, 'reason' => 'Удаление недоступно: ' . $error];
        }

        return ['allowed' => true, 'reason' => null];
    }

    private function disableSubscriptionOnServer(UserSubscription $userSub): array
    {
        [$ok, $error, $server, $peerName] = $this->resolveSubscriptionServerTarget($userSub);
        if (!$ok || !$server || !$peerName) {
            return [false, $error];
        }

        $peerOperator = app(SubscriptionPeerOperator::class);

        if ($server->usesNode1Api()) {
            try {
                $peerOperator->disableNodePeer($server, $peerName, true);
                $peerOperator->syncServerState($server, $peerName, 'disabled', (int) $userSub->user_id);
            } catch (\Throwable $e) {
                return [false, $e->getMessage()];
            }
        } else {
            try {
                $peerOperator->disableInboundPeer($server, $peerName);
                $peerOperator->syncServerState($server, $peerName, 'disabled', (int) $userSub->user_id);
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'unsuccessful response') {
                    return [false, 'Не удалось отключить inbound'];
                }

                return [false, 'Ошибка отключения inbound: ' . $e->getMessage()];
            }
        }

        return [true, null];
    }

    private function resolveSubscriptionServerTarget(UserSubscription $userSub): array
    {
        $path = (string) ($userSub->file_path ?? '');
        if ($path === '') {
            return [false, 'file_path пуст', null, null];
        }

        $meta = SubscriptionBundleMeta::fromFilePath($path);
        if ($meta === null) {
            return [false, 'Не удалось разобрать имя peer/server_id из file_path', null, null];
        }

        $server = Server::query()->find($meta->serverId());
        if (!$server) {
            return [false, "Сервер не найден (id={$meta->serverId()})", null, null];
        }

        return [true, null, $server, $meta->peerName()];
    }
}
