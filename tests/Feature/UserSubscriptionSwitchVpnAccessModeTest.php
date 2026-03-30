<?php

namespace Tests\Feature;

use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionSwitchVpnAccessModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_vpn_access_mode_without_creating_new_billing_row(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
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

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $white->id);
        ProjectSetting::setValue(Server::CURRENT_REGULAR_SERVER_SETTING, (string) $regular->id);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_device-main_' . $white->id . '_26_03_2026_18_00.zip',
            'connection_config' => 'vless://old#device-main',
            'server_id' => $white->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $response = $this->actingAs($user)->getJson(route('user-subscription.switch-vpn-access-mode', [
            'user_subscription_id' => $userSub->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]));

        $response->assertOk();
        $message = (string) $response->json('message');
        $this->assertStringStartsWith('Новое подключение готово. Старая настройка отключится автоматически в ', $message);
        $this->assertStringEndsWith(' МСК.', $message);

        $this->assertSame(1, UserSubscription::query()->count());

        $updated = $userSub->fresh();
        $this->assertNotNull($updated);
        $this->assertSame((int) $regular->id, (int) $updated->server_id);
        $this->assertSame(Server::VPN_ACCESS_REGULAR, (string) $updated->vpn_access_mode);
        $this->assertStringContainsString('_' . $regular->id . '_', (string) $updated->file_path);
        $this->assertStringContainsString('#device-main', (string) $updated->connection_config);
        $this->assertSame((int) $white->id, (int) $updated->pending_vpn_access_mode_source_server_id);
        $this->assertSame('device-main', (string) $updated->pending_vpn_access_mode_source_peer_name);
        $this->assertNotNull($updated->pending_vpn_access_mode_disconnect_at);
        $this->assertNull($updated->pending_vpn_access_mode_error);

        $cardsHtml = (string) $response->json('cards_html');
        $this->assertStringContainsString('Обычное подключение', $cardsHtml);
        $this->assertStringContainsString('Инструкция', $cardsHtml);
        $this->assertStringContainsString('Старая настройка отключится автоматически', $cardsHtml);
        $this->assertStringNotContainsString('Переключить на подключение при ограничениях', $cardsHtml);
    }

    public function test_user_cannot_start_second_mode_switch_while_grace_period_is_active(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $regular = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => Subscription::factory()->create([
                'name' => 'VPN',
                'price' => 5000,
            ])->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_device-main_' . $regular->id . '_30_03_2026_10_00.zip',
            'connection_config' => 'vless://new#device-main',
            'server_id' => $regular->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'pending_vpn_access_mode_source_server_id' => 10,
            'pending_vpn_access_mode_source_peer_name' => 'device-main',
            'pending_vpn_access_mode_disconnect_at' => now()->addMinutes(4),
        ]);

        $response = $this->actingAs($user)->getJson(route('user-subscription.switch-vpn-access-mode', [
            'user_subscription_id' => $userSub->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]));

        $response->assertStatus(409);
        $this->assertStringStartsWith(
            'Новое подключение уже подготовлено. Старая настройка отключится автоматически в ',
            (string) $response->json('message')
        );
    }
}
