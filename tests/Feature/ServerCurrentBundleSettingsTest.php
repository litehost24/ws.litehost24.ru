<?php

namespace Tests\Feature;

use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerCurrentBundleSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_current_bundle_controls_on_servers_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Какие серверы выдаём новым подпискам')
            ->assertSee('Подключение при ограничениях')
            ->assertSee('Обычное подключение');
    }

    public function test_admin_can_save_current_bundle_server_ids(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $white = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $regular = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $response = $this->actingAs($admin)->post(route('servers.current-bundles'), [
            'white_ip_server_id' => $white->id,
            'regular_server_id' => $regular->id,
        ]);

        $response->assertRedirect(route('servers.index'));
        $response->assertSessionHas('success', 'Текущие VPN bundle обновлены.');

        $this->assertSame(
            (string) $white->id,
            ProjectSetting::getValue(Server::CURRENT_WHITE_IP_SERVER_SETTING)
        );
        $this->assertSame(
            (string) $regular->id,
            ProjectSetting::getValue(Server::CURRENT_REGULAR_SERVER_SETTING)
        );
        $this->assertSame((int) $white->id, (int) Server::resolvePurchaseServer(Server::VPN_ACCESS_WHITE_IP)?->id);
        $this->assertSame((int) $regular->id, (int) Server::resolvePurchaseServer(Server::VPN_ACCESS_REGULAR)?->id);
    }
}
