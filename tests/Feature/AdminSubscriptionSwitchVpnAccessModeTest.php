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

class AdminSubscriptionSwitchVpnAccessModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_switch_subscription_vpn_access_mode_without_creating_new_billing_row(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

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

        $indexResponse = $this->actingAs($admin)->get(route('admin.subscriptions.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee(route('admin.subscriptions.switch-vpn-access-mode', ['userSubscription' => $userSub->id], false), false);

        $response = $this->actingAs($admin)->post(route('admin.subscriptions.switch-vpn-access-mode', [
            'userSubscription' => $userSub->id,
        ]), [
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('subscription-success', 'Тип подключения подписки изменён. Старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг.');

        $this->assertSame(1, UserSubscription::query()->count());

        $updated = $userSub->fresh();
        $this->assertNotNull($updated);
        $this->assertSame((int) $regular->id, (int) $updated->server_id);
        $this->assertSame(Server::VPN_ACCESS_REGULAR, (string) $updated->vpn_access_mode);
        $this->assertStringContainsString('_' . $regular->id . '_', (string) $updated->file_path);
        $this->assertStringContainsString('#device-main', (string) $updated->connection_config);
    }
}
