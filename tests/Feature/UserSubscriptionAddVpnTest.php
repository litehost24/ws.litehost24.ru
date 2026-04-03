<?php

namespace Tests\Feature;

use App\Models\Payment;
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

class UserSubscriptionAddVpnTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_vpn_uses_first_free_vpn_slot(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $vpnA = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $vpnB = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $vpnC = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/device_a_1_1.zip',
            'connection_config' => 'vless://device-a#device-a',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnC->id,
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/device_c_1_3.zip',
            'connection_config' => 'vless://device-c#device-c',
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.add-vpn'), [
            'note' => 'Tablet',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'VPN подключен',
            ]);

        $created = UserSubscription::query()->orderByDesc('id')->first();

        $this->assertNotNull($created);
        $this->assertSame((int) $vpnB->id, (int) $created->subscription_id);
        $this->assertSame('Tablet', $created->note);
    }

    public function test_add_vpn_saves_selected_bundle_mode_on_subscription(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
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

        Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.add-vpn'), [
            'note' => 'Phone',
            'need_white_ip' => 1,
        ]);

        $response->assertOk();

        $created = UserSubscription::query()->latest('id')->first();

        $this->assertNotNull($created);
        $this->assertSame((int) $white->id, (int) $created->server_id);
        $this->assertSame(Server::VPN_ACCESS_WHITE_IP, (string) $created->vpn_access_mode);
        $this->assertSame('restricted_standard', (string) $created->vpn_plan_code);
        $this->assertSame('Стандарт', (string) $created->vpn_plan_name);
        $this->assertSame(30 * 1024 * 1024 * 1024, (int) $created->vpn_traffic_limit_bytes);
        $this->assertSame(20000, (int) $created->price);

        $peerName = VpnPeerName::fromSubscription($created, $created->resolveServerId());
        $this->assertNotNull($peerName);

        $state = VpnPeerServerState::query()
            ->where('server_id', $white->id)
            ->where('peer_name', $peerName)
            ->first();

        $this->assertNotNull($state);
        $this->assertSame('enabled', (string) $state->server_status);
        $this->assertSame((int) $user->id, (int) $state->user_id);
    }

    public function test_add_vpn_uses_explicit_selected_plan_for_price_and_snapshot(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $white = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $white->id);

        Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.add-vpn'), [
            'note' => 'Laptop',
            'vpn_plan_code' => 'restricted_premium',
        ]);

        $response->assertOk();

        $created = UserSubscription::query()->latest('id')->first();

        $this->assertNotNull($created);
        $this->assertSame(Server::VPN_ACCESS_WHITE_IP, (string) $created->vpn_access_mode);
        $this->assertSame('restricted_premium', (string) $created->vpn_plan_code);
        $this->assertSame('Премиум', (string) $created->vpn_plan_name);
        $this->assertSame(50 * 1024 * 1024 * 1024, (int) $created->vpn_traffic_limit_bytes);
        $this->assertSame(30000, (int) $created->price);
        $this->assertSame('Laptop', (string) $created->note);
    }

    public function test_add_vpn_uses_plan_specific_purchase_server_override(): void
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

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $mtsServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $defaultWhite = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $defaultWhite->id);
        ProjectSetting::setValue('vpn_bundle_mts_beta_server_id', (string) $mtsServer->id);

        Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.add-vpn'), [
            'note' => 'MTS Phone',
            'vpn_plan_code' => 'restricted_mts_beta',
        ]);

        $response->assertOk();

        $created = UserSubscription::query()->latest('id')->first();

        $this->assertNotNull($created);
        $this->assertSame((int) $mtsServer->id, (int) $created->server_id);
        $this->assertSame('restricted_mts_beta', (string) $created->vpn_plan_code);
        $this->assertSame('Для сети МТС (бета)', (string) $created->vpn_plan_name);
        $this->assertNull($created->vpn_traffic_limit_bytes);
        $this->assertSame(10000, (int) $created->price);
        $this->assertSame('MTS Phone', (string) $created->note);
    }
}
