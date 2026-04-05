<?php

namespace Tests\Feature;

use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VpnPeerServerState;
use App\Support\VpnPeerName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionSwitchMtsBetaToEconomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_mts_beta_subscription_to_economy_without_new_charge(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $mtsServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $economyServer = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        ProjectSetting::setValue('vpn_bundle_mts_beta_server_id', (string) $mtsServer->id);
        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $economyServer->id);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peermts_' . $mtsServer->id . '_05_04_2026_10_00.zip',
            'server_id' => $mtsServer->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => 'restricted_mts_beta',
            'vpn_plan_name' => 'Для сети МТС (бета)',
            'vpn_traffic_limit_bytes' => null,
            'note' => 'Phone',
        ]);

        $response = $this->actingAs($user)->getJson(route('user-subscription.switch-mts-beta-to-economy', [
            'user_subscription_id' => $userSub->id,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('Тариф переключён на Эконом.', (string) $response->json('message'));

        $updated = $userSub->fresh();
        $this->assertNotNull($updated);
        $this->assertSame((int) $economyServer->id, (int) $updated->server_id);
        $this->assertSame(Server::VPN_ACCESS_WHITE_IP, (string) $updated->vpn_access_mode);
        $this->assertSame('restricted_economy', (string) $updated->vpn_plan_code);
        $this->assertSame('Эконом', (string) $updated->vpn_plan_name);
        $this->assertSame(10 * 1024 * 1024 * 1024, (int) $updated->vpn_traffic_limit_bytes);
        $this->assertSame(10000, (int) $updated->price);
        $this->assertSame('Phone', (string) $updated->note);
        $this->assertSame(Carbon::today()->addDays(20)->toDateString(), (string) $updated->end_date);
        $this->assertNull($updated->pending_vpn_access_mode_source_server_id);
        $this->assertNull($updated->pending_vpn_access_mode_source_peer_name);
        $this->assertNull($updated->pending_vpn_access_mode_disconnect_at);

        $oldPeerName = 'peermts';
        $newPeerName = VpnPeerName::fromSubscription($updated, $updated->resolveServerId());

        $this->assertNotNull($newPeerName);
        $this->assertNotSame($oldPeerName, $newPeerName);
        $this->assertStringContainsString('_' . $economyServer->id . '_', (string) $updated->file_path);

        $targetState = VpnPeerServerState::query()
            ->where('server_id', $economyServer->id)
            ->where('peer_name', $newPeerName)
            ->first();
        $this->assertNotNull($targetState);
        $this->assertSame('enabled', (string) $targetState->server_status);
        $this->assertSame((int) $user->id, (int) $targetState->user_id);

        $sourceState = VpnPeerServerState::query()
            ->where('server_id', $mtsServer->id)
            ->where('peer_name', $oldPeerName)
            ->first();
        $this->assertNotNull($sourceState);
        $this->assertSame('disabled', (string) $sourceState->server_status);
        $this->assertSame((int) $user->id, (int) $sourceState->user_id);
    }

    public function test_non_mts_beta_subscription_cannot_use_switch_route(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $server = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peereconomy_' . $server->id . '_05_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => 'restricted_economy',
            'vpn_plan_name' => 'Эконом',
            'vpn_traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        ]);

        $response = $this->actingAs($user)->getJson(route('user-subscription.switch-mts-beta-to-economy', [
            'user_subscription_id' => $userSub->id,
        ]));

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Переход на Эконом доступен только для тарифа Для сети МТС (бета).',
        ]);
    }
}
