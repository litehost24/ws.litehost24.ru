<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Server extends Model
{
    use HasFactory;

    public const VPN_ACCESS_WHITE_IP = 'white_ip';
    public const VPN_ACCESS_REGULAR = 'regular';
    public const CURRENT_WHITE_IP_SERVER_SETTING = 'vpn_bundle_white_ip_server_id';
    public const CURRENT_REGULAR_SERVER_SETTING = 'vpn_bundle_regular_server_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'ip1',
        'username1', 
        'password1',
        'webwasepath1',
        'url1',
        'node1_api_url',
        'node1_api_ca_path',
        'node1_api_cert_path',
        'node1_api_key_path',
        'node1_api_enabled',
        'vpn_access_mode',
        'ip2',
        'username2',
        'password2', 
        'webwasepath2',
        'url2',
        'vless_inbound_id',
    ];

    protected $casts = [
        'node1_api_enabled' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password1',
        'password2',
    ];

    public function usesNode1Api(): bool
    {
        return (bool) $this->node1_api_enabled;
    }

    /**
     * @return array<string, string>
     */
    public static function vpnAccessModeOptions(): array
    {
        return [
            self::VPN_ACCESS_WHITE_IP => 'Подключение при ограничениях',
            self::VPN_ACCESS_REGULAR => 'Домашний интернет',
        ];
    }

    public static function normalizeVpnAccessMode(?string $value): string
    {
        $value = trim((string) $value);

        return array_key_exists($value, self::vpnAccessModeOptions())
            ? $value
            : self::VPN_ACCESS_WHITE_IP;
    }

    public function getVpnAccessMode(): string
    {
        return self::normalizeVpnAccessMode((string) $this->vpn_access_mode);
    }

    public function vpnAccessModeLabel(): string
    {
        $mode = $this->getVpnAccessMode();

        return self::vpnAccessModeOptions()[$mode] ?? self::vpnAccessModeOptions()[self::VPN_ACCESS_WHITE_IP];
    }

    public static function resolvePurchaseServer(?string $vpnAccessMode = null, ?string $vpnPlanCode = null): ?self
    {
        if (!Schema::hasTable('servers')) {
            return self::query()->orderBy('id', 'desc')->first();
        }

        $planMode = trim((string) config('vpn_plans.plans.' . trim((string) $vpnPlanCode) . '.vpn_access_mode', ''));
        $resolvedMode = $vpnAccessMode !== null && trim($vpnAccessMode) !== ''
            ? self::normalizeVpnAccessMode($vpnAccessMode)
            : ($planMode !== '' ? self::normalizeVpnAccessMode($planMode) : null);

        $planServer = self::resolveConfiguredPlanServer($vpnPlanCode, $resolvedMode);
        if ($planServer) {
            return $planServer;
        }

        if ($resolvedMode === null) {
            return self::query()->orderBy('id', 'desc')->first();
        }

        $configured = self::resolveConfiguredBundleServer($resolvedMode);
        if ($configured) {
            return $configured;
        }

        if (!Schema::hasColumn('servers', 'vpn_access_mode')) {
            return self::query()->orderBy('id', 'desc')->first();
        }

        return self::query()
            ->where('vpn_access_mode', $resolvedMode)
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function currentBundleServerSettingKey(string $vpnAccessMode): string
    {
        return self::normalizeVpnAccessMode($vpnAccessMode) === self::VPN_ACCESS_REGULAR
            ? self::CURRENT_REGULAR_SERVER_SETTING
            : self::CURRENT_WHITE_IP_SERVER_SETTING;
    }

    private static function resolveConfiguredBundleServer(string $vpnAccessMode): ?self
    {
        $settingKey = self::currentBundleServerSettingKey($vpnAccessMode);
        $serverId = ProjectSetting::getInt($settingKey, 0);
        if ($serverId <= 0) {
            return null;
        }

        $server = self::query()->find($serverId);
        if (!$server) {
            return null;
        }

        if (Schema::hasColumn('servers', 'vpn_access_mode') && $server->getVpnAccessMode() !== self::normalizeVpnAccessMode($vpnAccessMode)) {
            return null;
        }

        return $server;
    }

    private static function resolveConfiguredPlanServer(?string $vpnPlanCode, ?string $vpnAccessMode = null): ?self
    {
        $planCode = trim((string) $vpnPlanCode);
        if ($planCode === '') {
            return null;
        }

        $settingKey = trim((string) config('vpn_plans.plans.' . $planCode . '.purchase_server_setting', ''));
        if ($settingKey === '') {
            return null;
        }

        $serverId = ProjectSetting::getInt($settingKey, 0);
        if ($serverId <= 0) {
            return null;
        }

        $server = self::query()->find($serverId);
        if (!$server) {
            return null;
        }

        if (
            $vpnAccessMode !== null
            && Schema::hasColumn('servers', 'vpn_access_mode')
            && $server->getVpnAccessMode() !== self::normalizeVpnAccessMode($vpnAccessMode)
        ) {
            return null;
        }

        return $server;
    }
}
