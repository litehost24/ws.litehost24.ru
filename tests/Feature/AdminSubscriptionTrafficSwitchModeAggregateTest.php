<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSubscriptionTrafficSwitchModeAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_sums_traffic_across_servers_for_same_peer_after_ip_mode_switch(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        $white = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://white.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $regular = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://regular.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_samepeer_' . $white->id . '_26_03_2026_10_00.zip',
            'server_id' => $white->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'file_path' => 'files/' . $user->id . '_samepeer_' . $regular->id . '_27_03_2026_10_00.zip',
            'server_id' => $regular->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            [
                'date' => Carbon::today()->toDateString(),
                'server_id' => $white->id,
                'user_id' => $user->id,
                'peer_name' => 'samepeer',
                'public_key' => 'pk-white',
                'ip' => '10.66.66.10/32',
                'rx_bytes_delta' => 1024,
                'tx_bytes_delta' => 1024,
                'total_bytes_delta' => 2048,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'date' => Carbon::today()->toDateString(),
                'server_id' => $regular->id,
                'user_id' => $user->id,
                'peer_name' => 'samepeer',
                'public_key' => 'pk-regular',
                'ip' => '10.78.78.10/32',
                'rx_bytes_delta' => 3072,
                'tx_bytes_delta' => 1024,
                'total_bytes_delta' => 4096,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('6.00 KB', false);
    }
}
