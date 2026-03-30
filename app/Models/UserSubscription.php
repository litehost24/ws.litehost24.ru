<?php

namespace App\Models;

use App\Support\VpnPeerName;
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
        'pending_vpn_access_mode_source_server_id',
        'pending_vpn_access_mode_source_peer_name',
        'pending_vpn_access_mode_disconnect_at',
        'pending_vpn_access_mode_error',
        'vless_blocked_until',
        'note',
    ];

    protected $casts = [
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

        if (($this->end_date ?? null) === self::AWAIT_PAYMENT_DATE) {
            return false;
        }

        if (($this->action ?? null) === 'deactivate') {
            return false;
        }

        return true;
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

        return $mode === Server::VPN_ACCESS_WHITE_IP
            ? Server::VPN_ACCESS_REGULAR
            : Server::VPN_ACCESS_WHITE_IP;
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

        return $this->resolveServerId() !== null && $this->switchTargetVpnAccessMode() !== null;
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
        if (!is_string($filePath) || trim($filePath) === '') {
            return null;
        }

        $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        $parts = explode('_', $base);
        if (count($parts) < 3) {
            return null;
        }

        $serverId = (int) $parts[2];
        return $serverId > 0 ? $serverId : null;
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
        $filePath = trim((string) ($this->file_path ?? ''));
        if ($filePath !== '') {
            return 'file:' . $filePath;
        }

        $peerName = VpnPeerName::fromSubscription($this);
        if (!empty($peerName)) {
            return 'peer:' . $peerName;
        }

        $config = trim((string) ($this->connection_config ?? ''));
        if ($config !== '') {
            return 'config:' . $config;
        }

        return 'row:' . (int) $this->id;
    }
}

