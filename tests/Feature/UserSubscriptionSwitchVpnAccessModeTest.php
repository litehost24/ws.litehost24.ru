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

        $response->assertOk()
            ->assertJson([
                'message' => 'Тип подключения изменён. Скачайте новый конфиг для устройства.',
            ]);

        $this->assertSame(1, UserSubscription::query()->count());

        $updated = $userSub->fresh();
        $this->assertNotNull($updated);
        $this->assertSame((int) $regular->id, (int) $updated->server_id);
        $this->assertSame(Server::VPN_ACCESS_REGULAR, (string) $updated->vpn_access_mode);
        $this->assertStringContainsString('_' . $regular->id . '_', (string) $updated->file_path);
        $this->assertStringContainsString('#device-main', (string) $updated->connection_config);

        $cardsHtml = (string) $response->json('cards_html');
        $this->assertStringContainsString('Обычный AWG + VLESS', $cardsHtml);
        $this->assertStringContainsString('Переключить на белый IP', $cardsHtml);
        $this->assertStringContainsString('AmneziaVPN (Android)', $cardsHtml);
        $this->assertStringContainsString('AmneziaWG (iPhone)', $cardsHtml);
        $this->assertStringContainsString('VLESS', $cardsHtml);
    }
}
