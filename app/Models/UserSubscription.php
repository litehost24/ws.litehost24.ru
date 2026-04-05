<?php

namespace App\Models;

use App\Services\VpnPlanCatalog;
use App\Support\VpnPeerName;
use App\Support\SubscriptionBundleMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\VpnPeerTrafficSnapshot;

/**
 * @property integer $id
 * @property integer $subscription_id
 * @property integer $user_id
 * @property integer $price
 * @property string  $action - possible variables: create, activate, deactivate
 * @property boolean $is_processed
 * @property boolean $is_rebilling
 * @property Carbon  $end_date
 * @property string  $file_path
 * @property string  $note
 * @property Carbon  $created_at
 * @property Carbon  $updated_at
 * @property Subscription $subscription
 *
 * @method static create(array $array)
 * @method static where(string $string, mixed $string, ?string $string)
 * @method static orderBy(string $string, string $string1)
 */
class UserSubscription extends Model
{
    public const NEXT_PLAN_CONFIG_GRACE_HOURS = 24;

    use HasFactory;

    public const AWAIT_PAYMENT_DATE = '9999-01-01';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'price',
        'action',
        'is_processed',
        'is_rebilling',
        'end_date',
        'file_path',
        'connection_config',
        'server_id',
        'vpn_access_mode',
        'vpn_plan_code',
        'vpn_plan_name',
        'vpn_traffic_limit_bytes',
        'next_vpn_plan_code',
        'pending_vpn_access_mode_source_server_id',
        'pending_vpn_access_mode_source_peer_name',
        'pending_vpn_access_mode_disconnect_at',
        'pending_vpn_access_mode_error',
        'vless_blocked_until',
        'note',
    ];

    protected $casts = [
        'vpn_traffic_limit_bytes' => 'integer',
        'pending_vpn_access_mode_disconnect_at' => 'datetime',
        'vless_blocked_until' => 'datetime',
        'dual_protocol_last_seen_at' => 'datetime',
    ];

    public static function connectedQuery(?int $userId = null): Builder
    {
        return UserSubscription::query()
            ->when($userId !== null, function (Builder $query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where(function ($query) {
                $query->whereDate('end_date', '>', Carbon::today()->toDateString()) // РђРєС‚РёРІРЅС‹Рµ РїРѕРґРїРёСЃРєРё
                      ->orWhere(function ($q) { // РР»Рё РїРѕРґРїРёСЃРєРё, РєРѕС‚РѕСЂС‹Рµ РёСЃС‚РµРєР»Рё, РЅРѕ РѕР¶РёРґР°СЋС‚ РѕРїР»Р°С‚С‹
                          $q->whereDate('end_date', '<=', Carbon::today()->toDateString())
                            ->where('is_processed', false)
                            ->where('is_rebilling', true);
                      })
                      ->orWhere('end_date', UserSubscription::AWAIT_PAYMENT_DATE); // РР»Рё РїРѕРґРїРёСЃРєРё, РѕР¶РёРґР°СЋС‰РёРµ РѕРїР»Р°С‚С‹ СЃ РѕСЃРѕР±РѕР№ РґР°С‚РѕР№
            });
    }

    public static function whereIsConnected(?int $userId = null): Builder
    {
        $userId = $userId ?? (Auth::id() ? (int) Auth::id() : null);
        if (!$userId) {
            return UserSubscription::query()->whereRaw('1 = 0');
        }

        return self::connectedQuery($userId);
    }

    public static function getActiveList(?int $userId = null): Collection
    {
        // Показываем по одной последней записи на устройство/конфиг, а не схлопываем по subscription_id.
        $connectedSubs = UserSubscription::whereIsConnected($userId)
            ->orderBy('id', 'desc')
            ->get()
            ->load('subscription');

        $uniqueSubs = collect([]);
        $processedKeys = [];

        foreach ($connectedSubs as $sub) {
            $deviceKey = $sub->cabinetDeviceKey();
            if (!isset($processedKeys[$deviceKey])) {
                $uniqueSubs->push($sub);
                $processedKeys[$deviceKey] = true;
            }
        }

        return $uniqueSubs;
    }

    public static function getCabinetList(?int $userId = null): Collection
    {
        $userId = $userId ?? (Auth::id() ? (int) Auth::id() : null);
        if (!$userId) {
            return collect();
        }

        $connectedSubs = self::getActiveList($userId);
        $connectedSubscriptionIds = $connectedSubs
            ->pluck('subscription_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $latestIds = UserSubscription::query()
            ->where('user_id', $userId)
            ->select(DB::raw('MAX(id)'))
            ->groupBy('subscription_id');

        $latestBySubscription = UserSubscription::query()
            ->whereIn('id', $latestIds)
            ->orderBy('id', 'desc')
            ->get()
            ->load('subscription');

        $expiredOrInactive = $latestBySubscription->reject(function (self $sub) use ($connectedSubscriptionIds) {
            return in_array((int) $sub->subscription_id, $connectedSubscriptionIds, true);
        });

        return $connectedSubs
            ->concat($expiredOrInactive)
            ->sortByDesc('id')
            ->values();
    }

    public static function attachTrafficTotals(Collection $subscriptions): Collection
    {
        if ($subscriptions->isEmpty()) {
            return $subscriptions;
        }

        if (!Schema::hasTable('vpn_peer_traffic_daily')) {
            foreach ($subscriptions as $sub) {
                $sub->traffic_total_bytes = null;
                $sub->traffic_total_bytes_vless = null;
            }
            return $subscriptions;
        }

        $items = $subscriptions->filter(fn ($sub) => $sub instanceof self)->values();
        if ($items->isEmpty()) {
            return $subscriptions;
        }

        $userIds = [];
        $peerNames = [];
        $serverIds = [];
        $meta = [];

        foreach ($items as $sub) {
            $userId = (int) ($sub->user_id ?? 0);
            $peerName = VpnPeerName::fromSubscription($sub);
            $serverId = $sub->resolveServerId();

            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
            if (!empty($peerName)) {
                $peerNames[$peerName] = $peerName;
            }
            if ($serverId !== null) {
                $serverIds[$serverId] = $serverId;
            }

            $meta[(int) $sub->id] = [
                'user_id' => $userId,
                'peer_name' => $peerName,
                'server_id' => $serverId,
            ];
        }

        if (empty($userIds) || empty($peerNames)) {
            foreach ($subscriptions as $sub) {
                $sub->traffic_total_bytes = null;
                $sub->traffic_total_bytes_vless = null;
            }
            return $subscriptions;
        }

        $trafficByUserServerPeer = VpnPeerTrafficDaily::query()
            ->select('user_id', 'server_id', 'peer_name', DB::raw('SUM(total_bytes_delta) as total_bytes'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->when(!empty($serverIds), function ($query) use ($serverIds) {
                $query->whereIn('server_id', array_values($serverIds));
            })
            ->groupBy('user_id', 'server_id', 'peer_name')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (int) $row->server_id . ':' . (string) $row->peer_name;
            });

        $trafficByUserPeer = VpnPeerTrafficDaily::query()
            ->select('user_id', 'peer_name', DB::raw('SUM(total_bytes_delta) as total_bytes'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->groupBy('user_id', 'peer_name')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (string) $row->peer_name;
            });

        $trafficServerCountByUserPeer = VpnPeerTrafficDaily::query()
            ->select('user_id', 'peer_name', DB::raw('COUNT(DISTINCT server_id) as server_count'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->groupBy('user_id', 'peer_name')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (string) $row->peer_name;
            });

        $trafficVlessByUserServerPeer = VpnPeerTrafficDaily::query()
            ->select('user_id', 'server_id', 'peer_name', DB::raw('SUM(vless_total_bytes_delta) as total_bytes'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->when(!empty($serverIds), function ($query) use ($serverIds) {
                $query->whereIn('server_id', array_values($serverIds));
            })
            ->groupBy('user_id', 'server_id', 'peer_name')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (int) $row->server_id . ':' . (string) $row->peer_name;
            });

        $trafficVlessByUserPeer = VpnPeerTrafficDaily::query()
            ->select('user_id', 'peer_name', DB::raw('SUM(vless_total_bytes_delta) as total_bytes'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->groupBy('user_id', 'peer_name')
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->user_id . ':' . (string) $row->peer_name;
            });

        $snapshotByServerPeer = VpnPeerTrafficSnapshot::query()
            ->select('server_id', 'peer_name', 'rx_bytes', 'tx_bytes', 'vless_rx_bytes', 'vless_tx_bytes')
            ->whereIn('peer_name', array_values($peerNames))
            ->when(!empty($serverIds), function ($query) use ($serverIds) {
                $query->whereIn('server_id', array_values($serverIds));
            })
            ->get()
            ->keyBy(function ($row) {
                return (int) $row->server_id . ':' . (string) $row->peer_name;
            });

        foreach ($subscriptions as $sub) {
            $subId = (int) ($sub->id ?? 0);
            $info = $meta[$subId] ?? null;
            if (!$info || empty($info['peer_name']) || empty($info['user_id'])) {
                $sub->traffic_total_bytes = null;
                $sub->traffic_total_bytes_vless = null;
                continue;
            }

            $bytes = null;
            $bytesVless = null;
            $allServersKey = $info['user_id'] . ':' . $info['peer_name'];
            $hasMultiServerHistory = (int) ($trafficServerCountByUserPeer->get($allServersKey)?->server_count ?? 0) > 1;

            if ($hasMultiServerHistory) {
                $bytes = $trafficByUserPeer->get($allServersKey)?->total_bytes;
                $bytesVless = $trafficVlessByUserPeer->get($allServersKey)?->total_bytes;
            } elseif (!empty($info['server_id'])) {
                $key = $info['user_id'] . ':' . $info['server_id'] . ':' . $info['peer_name'];
                $bytes = $trafficByUserServerPeer->get($key)?->total_bytes;
                $bytesVless = $trafficVlessByUserServerPeer->get($key)?->total_bytes;
            } else {
                $bytes = $trafficByUserPeer->get($allServersKey)?->total_bytes;
                $bytesVless = $trafficVlessByUserPeer->get($allServersKey)?->total_bytes;
            }

            if (!empty($info['server_id'])) {
                $snapshotKey = $info['server_id'] . ':' . $info['peer_name'];
                $snapshot = $snapshotByServerPeer->get($snapshotKey);

                if ($snapshot) {
                    $snapshotBytes = (int) ($snapshot->rx_bytes ?? 0) + (int) ($snapshot->tx_bytes ?? 0);
                    $snapshotBytesVless = (int) ($snapshot->vless_rx_bytes ?? 0) + (int) ($snapshot->vless_tx_bytes ?? 0);

                    if (((int) ($bytes ?? 0)) === 0 && $snapshotBytes > 0) {
                        $bytes = $snapshotBytes;
                    }

                    if (((int) ($bytesVless ?? 0)) === 0 && $snapshotBytesVless > 0) {
                        $bytesVless = $snapshotBytesVless;
                    }
                }
            }

            $sub->traffic_total_bytes = $bytes !== null ? (int) $bytes : 0;
            $sub->traffic_total_bytes_vless = $bytesVless !== null ? (int) $bytesVless : 0;
        }

        return $subscriptions;
    }

    public static function attachTrafficPeriodUsage(Collection $subscriptions): Collection
    {
        if ($subscriptions->isEmpty()) {
            return $subscriptions;
        }

        foreach ($subscriptions as $sub) {
            $limitBytes = $sub instanceof self ? $sub->vpnTrafficLimitBytes() : null;
            $sub->traffic_period_bytes = $limitBytes !== null ? 0 : null;
            $sub->traffic_topup_bytes = 0;
            $sub->traffic_available_bytes = $limitBytes;
            $sub->traffic_remaining_bytes = $limitBytes;
        }

        if (!Schema::hasTable('vpn_peer_traffic_daily')) {
            return $subscriptions;
        }

        $items = $subscriptions->filter(fn ($sub) => $sub instanceof self)->values();
        if ($items->isEmpty()) {
            return $subscriptions;
        }

        $userIds = [];
        $peerNames = [];
        $minDate = null;
        $userSubscriptionIds = [];
        $meta = [];
        $whiteIpServerIds = Server::query()
            ->where('vpn_access_mode', Server::VPN_ACCESS_WHITE_IP)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($items as $sub) {
            $userId = (int) ($sub->user_id ?? 0);
            $peerName = VpnPeerName::fromSubscription($sub);
            $startDate = $sub->created_at instanceof Carbon
                ? $sub->created_at->copy()->startOfDay()->toDateString()
                : Carbon::today()->toDateString();
            $userSubscriptionId = (int) ($sub->id ?? 0);

            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
            if ($peerName !== null && $peerName !== '') {
                $peerNames[$peerName] = $peerName;
            }
            if ($userSubscriptionId > 0) {
                $userSubscriptionIds[$userSubscriptionId] = $userSubscriptionId;
            }

            $meta[(int) $sub->id] = [
                'user_id' => $userId,
                'peer_name' => $peerName,
                'start_date' => $startDate,
            ];

            if ($minDate === null || $startDate < $minDate) {
                $minDate = $startDate;
            }
        }

        if (empty($userIds) || empty($peerNames) || $minDate === null) {
            return $subscriptions;
        }

        $dailyQuery = VpnPeerTrafficDaily::query()
            ->select('user_id', 'peer_name', 'date', DB::raw('SUM(total_bytes_delta) as total_bytes'))
            ->whereIn('user_id', array_values($userIds))
            ->whereIn('peer_name', array_values($peerNames))
            ->whereDate('date', '>=', $minDate)
            ->groupBy('user_id', 'peer_name', 'date')
            ->orderBy('date');

        if (!empty($whiteIpServerIds)) {
            $dailyQuery->whereIn('server_id', $whiteIpServerIds);
        }

        $daily = $dailyQuery->get();

        $dailyByUserPeer = [];
        foreach ($daily as $row) {
            $dailyByUserPeer[(int) $row->user_id . ':' . (string) $row->peer_name][] = [
                'date' => (string) $row->date,
                'bytes' => (int) $row->total_bytes,
            ];
        }

        $topupsByUserSubscriptionId = [];
        if (!empty($userSubscriptionIds) && Schema::hasTable('user_subscription_topups')) {
            $topupsByUserSubscriptionId = UserSubscriptionTopup::query()
                ->select('user_subscription_id', DB::raw('SUM(traffic_bytes) as total_traffic_bytes'))
                ->whereIn('user_subscription_id', array_values($userSubscriptionIds))
                ->groupBy('user_subscription_id')
                ->get()
                ->keyBy(fn ($row) => (int) $row->user_subscription_id);
        }

        foreach ($subscriptions as $sub) {
            $info = $meta[(int) ($sub->id ?? 0)] ?? null;
            if (!$info || empty($info['user_id']) || empty($info['peer_name'])) {
                continue;
            }

            $periodBytes = 0;
            $rows = $dailyByUserPeer[$info['user_id'] . ':' . $info['peer_name']] ?? [];
            foreach ($rows as $row) {
                if ((string) $row['date'] < (string) $info['start_date']) {
                    continue;
                }

                $periodBytes += (int) ($row['bytes'] ?? 0);
            }

            $sub->traffic_period_bytes = $periodBytes;
            $topupBytes = (int) ($topupsByUserSubscriptionId[(int) ($sub->id ?? 0)]->total_traffic_bytes ?? 0);
            $sub->traffic_topup_bytes = $topupBytes;
            $limitBytes = $sub->vpnTrafficLimitBytes();
            $sub->traffic_available_bytes = $limitBytes !== null
                ? ($limitBytes + $topupBytes)
                : null;
            $sub->traffic_remaining_bytes = $limitBytes !== null
                ? max(0, ($limitBytes + $topupBytes) - $periodBytes)
                : null;
        }

        return $subscriptions;
    }

    public static function nextMonthlyEndDate(?string $previousEndDate): string
    {
        $fallbackBase = Carbon::today()->startOfDay();
        $fallback = self::shiftMonthlyWithAnchor($fallbackBase);

        if (empty($previousEndDate) || $previousEndDate === self::AWAIT_PAYMENT_DATE) {
            return $fallback;
        }

        try {
            $base = Carbon::parse($previousEndDate)->startOfDay();
            return self::shiftMonthlyWithAnchor($base);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function isLocallyActive(): bool
    {
        if (!(bool) $this->is_processed) {
            return false;
        }

        $endDate = trim((string) ($this->end_date ?? ''));
        if ($endDate === '' || $endDate === self::AWAIT_PAYMENT_DATE) {
            return false;
        }

        if (($this->action ?? null) === 'deactivate') {
            return false;
        }

        try {
            return Carbon::parse($endDate)->toDateString() > Carbon::today()->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolveServerId(): ?int
    {
        $serverId = (int) ($this->server_id ?? 0);
        if ($serverId > 0) {
            return $serverId;
        }

        return self::extractServerIdFromFilePath((string) ($this->file_path ?? ''));
    }

    public function resolveServer(): ?Server
    {
        $serverId = $this->resolveServerId();
        if ($serverId === null) {
            return null;
        }

        return Server::query()->find($serverId);
    }

    public function resolveVpnAccessMode(): ?string
    {
        $mode = trim((string) ($this->vpn_access_mode ?? ''));
        if ($mode !== '') {
            return Server::normalizeVpnAccessMode($mode);
        }

        $server = $this->resolveServer();
        if (!$server) {
            return null;
        }

        return $server->getVpnAccessMode();
    }

    public function vpnAccessModeLabel(): ?string
    {
        $mode = $this->resolveVpnAccessMode();
        if ($mode === null) {
            return null;
        }

        return Server::vpnAccessModeOptions()[$mode] ?? null;
    }

    /**
     * User-facing labels for cabinet cards and action buttons.
     *
     * @return array<string, string>
     */
    public static function vpnAccessModeCabinetOptions(): array
    {
        return [
            Server::VPN_ACCESS_REGULAR => 'Домашний интернет',
            Server::VPN_ACCESS_WHITE_IP => 'Мобильная связь',
        ];
    }

    public function vpnAccessModeCabinetLabel(): ?string
    {
        return $this->vpnAccessModeCabinetLabelFor($this->resolveVpnAccessMode());
    }

    public function vpnAccessModeCabinetLabelFor(?string $mode): ?string
    {
        $mode = $mode !== null ? Server::normalizeVpnAccessMode($mode) : null;
        if ($mode === null) {
            return null;
        }

        return self::vpnAccessModeCabinetOptions()[$mode] ?? null;
    }

    public function vpnPlan(): ?array
    {
        $planCode = trim((string) ($this->vpn_plan_code ?? ''));
        if ($planCode === '') {
            return null;
        }

        return app(VpnPlanCatalog::class)->find($planCode);
    }

    public function isLegacyVpnPlan(): bool
    {
        return trim((string) ($this->vpn_plan_code ?? '')) === '';
    }

    public function vpnPlanLabel(): ?string
    {
        return app(VpnPlanCatalog::class)->displayLabelForUserSubscription($this);
    }

    public function nextVpnPlan(): ?array
    {
        $planCode = trim((string) ($this->next_vpn_plan_code ?? ''));
        if ($planCode === '') {
            return null;
        }

        return app(VpnPlanCatalog::class)->find($planCode);
    }

    public function nextVpnPlanLabel(): ?string
    {
        return $this->nextVpnPlan()['label'] ?? null;
    }

    public function vpnPlanNeedsNewConfig(?array $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        $targetMode = trim((string) ($plan['vpn_access_mode'] ?? ''));
        if ($targetMode === '') {
            return false;
        }

        $targetMode = Server::normalizeVpnAccessMode($targetMode);
        $currentMode = $this->resolveVpnAccessMode();
        if ($currentMode === null || $currentMode !== $targetMode) {
            return true;
        }

        $currentServerId = $this->resolveServerId();
        if ($currentServerId === null) {
            return true;
        }

        $planCode = trim((string) ($plan['code'] ?? $plan['vpn_plan_code'] ?? ''));
        $targetServer = Server::resolvePurchaseServer($targetMode, $planCode);

        return $targetServer !== null && $currentServerId !== (int) $targetServer->id;
    }

    public function nextVpnPlanNeedsNewConfig(): bool
    {
        return $this->vpnPlanNeedsNewConfig($this->nextVpnPlan());
    }

    public function vpnTrafficLimitBytes(): ?int
    {
        if ($this->vpn_traffic_limit_bytes !== null) {
            return max(0, (int) $this->vpn_traffic_limit_bytes);
        }

        $plan = $this->vpnPlan();
        if ($plan === null) {
            return null;
        }

        return $plan['traffic_limit_bytes'] !== null
            ? max(0, (int) $plan['traffic_limit_bytes'])
            : null;
    }

    public function allowsRestrictedMode(): bool
    {
        $planCode = trim((string) ($this->vpn_plan_code ?? ''));
        if ($planCode === '') {
            return true;
        }

        return app(VpnPlanCatalog::class)->allowsRestrictedMode($planCode);
    }

    public function hasPendingVpnAccessModeSwitch(): bool
    {
        return (int) ($this->pending_vpn_access_mode_source_server_id ?? 0) > 0
            && trim((string) ($this->pending_vpn_access_mode_source_peer_name ?? '')) !== ''
            && $this->pending_vpn_access_mode_disconnect_at !== null;
    }

    public function pendingVpnAccessModeDisconnectAt(): ?Carbon
    {
        $value = $this->pending_vpn_access_mode_disconnect_at;

        return $value instanceof Carbon ? $value : null;
    }

    public function switchTargetVpnAccessMode(): ?string
    {
        $mode = $this->resolveVpnAccessMode();
        if ($mode === null) {
            return null;
        }

        $targetMode = $mode === Server::VPN_ACCESS_WHITE_IP
            ? Server::VPN_ACCESS_REGULAR
            : Server::VPN_ACCESS_WHITE_IP;

        return $this->canSwitchToVpnAccessMode($targetMode) ? $targetMode : null;
    }

    public function canSwitchToVpnAccessMode(?string $targetMode): bool
    {
        $targetMode = trim((string) $targetMode);
        if ($targetMode === '') {
            return false;
        }

        $targetMode = Server::normalizeVpnAccessMode($targetMode);

        if ($targetMode === Server::VPN_ACCESS_WHITE_IP && !$this->allowsRestrictedMode()) {
            return false;
        }

        return true;
    }

    public function canSwitchVpnAccessMode(): bool
    {
        if (trim((string) ($this->file_path ?? '')) === '') {
            return false;
        }

        if ((string) ($this->action ?? '') === 'create' && !(bool) ($this->is_processed ?? false)) {
            return false;
        }

        if ($this->hasPendingVpnAccessModeSwitch()) {
            return false;
        }

        $targetMode = $this->switchTargetVpnAccessMode();

        return $this->resolveServerId() !== null
            && $targetMode !== null
            && $this->canSwitchToVpnAccessMode($targetMode);
    }

    private static function shiftMonthlyWithAnchor(Carbon $base): string
    {
        // Anchor monthly billing to end-of-month when base date is the month end.
        if ($base->isLastOfMonth()) {
            return $base->copy()->addMonthNoOverflow()->endOfMonth()->toDateString();
        }

        return $base->copy()->addMonthNoOverflow()->toDateString();
    }

    private static function extractServerIdFromFilePath(?string $filePath): ?int
    {
        return SubscriptionBundleMeta::fromFilePath($filePath)?->serverId();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cabinetDeviceKey(): string
    {
        $resolvedServerId = $this->resolveServerId();
        $peerName = trim((string) VpnPeerName::fromSubscription($this, $resolvedServerId));
        if ($resolvedServerId !== null && $peerName !== '') {
            return 'peer:' . $resolvedServerId . ':' . $peerName;
        }

        if ($peerName !== '') {
            return 'peer:' . $peerName;
        }

        $filePath = trim((string) ($this->file_path ?? ''));
        if ($filePath !== '') {
            return 'file:' . $filePath;
        }

        $config = trim((string) ($this->connection_config ?? ''));
        if ($config !== '') {
            return 'config:' . $config;
        }

        return 'row:' . (int) $this->id;
    }
}

