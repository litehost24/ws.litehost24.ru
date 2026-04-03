<?php

namespace Tests\Feature;

use App\Models\ProjectSetting;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerVpnAccessModeSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_purchase_server_returns_latest_white_ip_bundle(): void
    {
        Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $expected = Server::query()->create([
            'ip1' => '90.156.215.5',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $resolved = Server::resolvePurchaseServer(Server::VPN_ACCESS_WHITE_IP);

        $this->assertNotNull($resolved);
        $this->assertSame((int) $expected->id, (int) $resolved->id);
    }

    public function test_resolve_purchase_server_returns_regular_bundle_when_requested(): void
    {
        Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $expected = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $resolved = Server::resolvePurchaseServer(Server::VPN_ACCESS_REGULAR);

        $this->assertNotNull($resolved);
        $this->assertSame((int) $expected->id, (int) $resolved->id);
        $this->assertSame(Server::VPN_ACCESS_REGULAR, $resolved->getVpnAccessMode());
    }

    public function test_resolve_purchase_server_prefers_project_setting_for_white_ip_bundle(): void
    {
        $preferred = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        Server::query()->create([
            'ip1' => '90.156.215.5',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(
            Server::CURRENT_WHITE_IP_SERVER_SETTING,
            (string) $preferred->id
        );

        $resolved = Server::resolvePurchaseServer(Server::VPN_ACCESS_WHITE_IP);

        $this->assertNotNull($resolved);
        $this->assertSame((int) $preferred->id, (int) $resolved->id);
    }

    public function test_resolve_purchase_server_falls_back_to_latest_when_project_setting_is_invalid(): void
    {
        Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $expected = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(
            Server::CURRENT_REGULAR_SERVER_SETTING,
            '999999'
        );

        $resolved = Server::resolvePurchaseServer(Server::VPN_ACCESS_REGULAR);

        $this->assertNotNull($resolved);
        $this->assertSame((int) $expected->id, (int) $resolved->id);
    }

    public function test_resolve_purchase_server_prefers_plan_specific_setting_when_present(): void
    {
        config()->set('vpn_plans.plans.restricted_mts_beta', [
            'label' => 'Для сети МТС (бета)',
            'short_label' => 'МТС',
            'description' => 'Безлимит для мобильной сети МТС.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => null,
            'purchase_server_setting' => 'vpn_bundle_mts_beta_server_id',
        ]);

        $mts = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $default = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $default->id);
        ProjectSetting::setValue('vpn_bundle_mts_beta_server_id', (string) $mts->id);

        $resolved = Server::resolvePurchaseServer(Server::VPN_ACCESS_WHITE_IP, 'restricted_mts_beta');

        $this->assertNotNull($resolved);
        $this->assertSame((int) $mts->id, (int) $resolved->id);
    }
}
