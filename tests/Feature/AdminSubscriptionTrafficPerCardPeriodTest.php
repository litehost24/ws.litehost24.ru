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

class AdminSubscriptionTrafficPerCardPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_shows_traffic_for_current_row_period_only(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => false,
            'end_date' => Carbon::today()->subDays(3)->toDateString(),
            'created_at' => Carbon::today()->subDays(6),
            'updated_at' => Carbon::today()->subDays(3),
            'file_path' => 'files/' . $user->id . '_peerperiod_' . $server->id . '_27_03_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => Carbon::today()->subDay(),
            'updated_at' => Carbon::today()->subDay(),
            'file_path' => 'files/' . $user->id . '_peerperiod_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            [
                'date' => Carbon::today()->subDays(4)->toDateString(),
                'server_id' => $server->id,
                'user_id' => $user->id,
                'peer_name' => 'peerperiod',
                'public_key' => 'pk-peerperiod',
                'ip' => '10.66.66.50/32',
                'rx_bytes_delta' => 0,
                'tx_bytes_delta' => 0,
                'total_bytes_delta' => 5 * 1024 * 1024 * 1024,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'date' => Carbon::today()->subDay()->toDateString(),
                'server_id' => $server->id,
                'user_id' => $user->id,
                'peer_name' => 'peerperiod',
                'public_key' => 'pk-peerperiod',
                'ip' => '10.66.66.50/32',
                'rx_bytes_delta' => 0,
                'tx_bytes_delta' => 0,
                'total_bytes_delta' => 3 * 1024 * 1024 * 1024,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('Трафик за период', false);
        $response->assertSee('3.00 GB', false);
        $response->assertDontSee('8.00 GB', false);
    }
}
