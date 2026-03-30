<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VpnEndpointNetwork;
use App\Models\VpnPeerServerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionEndpointNetworkSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_page_shows_network_summary_and_user_tooltips(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $mobileUser = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
            'name' => 'Mobile User',
        ]);

        $fixedUser = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
            'name' => 'Fixed User',
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'url1' => 'https://node1.example',
            'username1' => 'u1',
            'password1' => 'p1',
            'url2' => 'https://node2.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);
        $now = Carbon::now();

        UserSubscription::factory()->create([
            'user_id' => $mobileUser->id,
            'subscription_id' => $subscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => $now->copy()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $mobileUser->id . '_79186873191_' . $server->id . '_28_03_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $fixedUser->id,
            'subscription_id' => $subscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => $now->copy()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $fixedUser->id . '_79186873192_' . $server->id . '_28_03_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $fixedUser->id,
            'subscription_id' => $subscription->id,
            'action' => 'activate',
            'is_processed' => true,
            'end_date' => $now->copy()->addDays(40)->toDateString(),
            'file_path' => 'files/' . $fixedUser->id . '_79186873193_' . $server->id . '_28_03_2026_12_05.zip',
            'server_id' => $server->id,
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $mobileUser->id,
            'peer_name' => '79186873191',
            'public_key' => 'pk-mobile',
            'ip' => '10.66.66.10/32',
            'endpoint' => '91.78.145.202:45678',
            'endpoint_ip' => '91.78.145.202',
            'endpoint_port' => 45678,
            'server_status' => 'enabled',
            'last_handshake_epoch' => $now->timestamp,
            'status_fetched_at' => $now,
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $fixedUser->id,
            'peer_name' => '79186873192',
            'public_key' => 'pk-fixed',
            'ip' => '10.66.66.11/32',
            'endpoint' => '178.67.196.220:45679',
            'endpoint_ip' => '178.67.196.220',
            'endpoint_port' => 45679,
            'server_status' => 'enabled',
            'last_handshake_epoch' => $now->timestamp,
            'status_fetched_at' => $now,
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $fixedUser->id,
            'peer_name' => '79186873193',
            'public_key' => 'pk-fixed-mobile',
            'ip' => '10.66.66.12/32',
            'endpoint' => '176.15.131.174:45680',
            'endpoint_ip' => '176.15.131.174',
            'endpoint_port' => 45680,
            'server_status' => 'enabled',
            'last_handshake_epoch' => $now->timestamp,
            'status_fetched_at' => $now->copy()->subHour(),
        ]);

        VpnEndpointNetwork::query()->create([
            'endpoint_ip' => '91.78.145.202',
            'as_number' => 8359,
            'as_name' => 'MTS, RU',
            'operator_label' => 'MTS',
            'network_type' => 'mobile',
            'last_checked_at' => $now,
        ]);

        VpnEndpointNetwork::query()->create([
            'endpoint_ip' => '178.67.196.220',
            'as_number' => 12389,
            'as_name' => 'ROSTELECOM-AS PJSC Rostelecom. Technical Team, RU',
            'operator_label' => 'Rostelecom',
            'network_type' => 'fixed',
            'last_checked_at' => $now,
        ]);

        VpnEndpointNetwork::query()->create([
            'endpoint_ip' => '176.15.131.174',
            'as_number' => 16345,
            'as_name' => 'BEE-AS Russia, RU',
            'operator_label' => 'Beeline',
            'network_type' => 'mobile',
            'last_checked_at' => $now,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('Сети по endpoint за 24ч');
        $response->assertSee('Мобильные:');
        $response->assertSee('Проводные:');
        $response->assertSee('Крупнейшие мобильные:');
        $response->assertSee('MTS');
        $response->assertSee('Крупнейшие fixed:');
        $response->assertSee('Мобильная сеть');
        $response->assertSee('Beeline');
    }
}
