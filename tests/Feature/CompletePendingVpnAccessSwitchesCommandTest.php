<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VpnPeerServerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompletePendingVpnAccessSwitchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_clears_pending_switch_after_grace_period(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $sourceServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        $targetServer = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://79.110.227.174:2053',
        ]);

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
            'file_path' => 'files/' . $user->id . '_device-main_' . $targetServer->id . '_30_03_2026_10_00.zip',
            'connection_config' => 'vless://new#device-main',
            'server_id' => $targetServer->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'pending_vpn_access_mode_source_server_id' => $sourceServer->id,
            'pending_vpn_access_mode_source_peer_name' => 'device-main',
            'pending_vpn_access_mode_disconnect_at' => now()->subMinute(),
            'pending_vpn_access_mode_error' => 'temporary',
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $sourceServer->id,
            'user_id' => $user->id,
            'peer_name' => 'device-main',
            'server_status' => 'enabled',
            'status_fetched_at' => now()->subMinutes(2),
        ]);

        $this->artisan('subscriptions:complete-vpn-access-switches')
            ->assertSuccessful();

        $updated = $userSub->fresh();
        $this->assertNotNull($updated);
        $this->assertNull($updated->pending_vpn_access_mode_source_server_id);
        $this->assertNull($updated->pending_vpn_access_mode_source_peer_name);
        $this->assertNull($updated->pending_vpn_access_mode_disconnect_at);
        $this->assertNull($updated->pending_vpn_access_mode_error);

        $sourceState = VpnPeerServerState::query()
            ->where('server_id', $sourceServer->id)
            ->where('peer_name', 'device-main')
            ->first();
        $this->assertNotNull($sourceState);
        $this->assertSame('disabled', (string) $sourceState->server_status);
        $this->assertSame((int) $user->id, (int) $sourceState->user_id);
        $this->assertTrue($sourceState->status_fetched_at !== null && $sourceState->status_fetched_at->greaterThan(now()->subMinute()));
    }
}
