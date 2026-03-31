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
}
